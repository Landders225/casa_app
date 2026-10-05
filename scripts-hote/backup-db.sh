#!/usr/bin/env bash
# =============================================================================
#  CASA — Sauvegarde quotidienne : dump PostgreSQL + volume des pièces
#  justificatives (docs/DEPLOIEMENT.md §12.1-§12.3).
#
#  Tourne sur l'HÔTE (pas dans un conteneur). Cible le conteneur postgres et le
#  volume des pièces par leur nom FIXE (`name: casa` — IDENTIQUE dans
#  docker-compose.prod.yml, docker-compose.test-local.yml ET
#  docker-compose.apache.yml, ADR-28) : ce script ne lit AUCUN .env, ne dépend
#  d'AUCUN override compose, et continue de fonctionner sans modification à
#  travers une bascule de mode (test-local <-> Apache) ou un changement de
#  répertoire de déploiement.
#
#  Usage :
#    scripts-hote/backup-db.sh
#
#  Installation (cron, compte de déploiement SANS sudo) :
#    crontab -e
#    # ajouter :
#    15 2 * * * /opt/casa/scripts-hote/backup-db.sh >> /srv/backups/backup.log 2>&1
#
#  Variables d'environnement (optionnelles) :
#    BACKUP_DIR       répertoire des sauvegardes (défaut /srv/backups)
#    RETENTION_DAYS   jours de rétention (défaut 14)
#
#  Restauration + procédure de test sur base JETABLE : voir
#  docs/DEPLOIEMENT.md §12.3.
# =============================================================================
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/srv/backups}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
STAMP="$(date +%Y%m%d-%H%M%S)"

mkdir -p "$BACKUP_DIR"

if ! docker inspect casa-postgres-1 >/dev/null 2>&1; then
    echo "[$(date -Iseconds)] ERREUR : conteneur casa-postgres-1 introuvable (CASA est-il démarré ?)" >&2
    exit 1
fi

# --- 1. Dump PostgreSQL (compressé) -----------------------------------------
DB_DUMP="$BACKUP_DIR/casa-db-$STAMP.sql.gz"
docker exec casa-postgres-1 pg_dump -U casa -d casa | gzip > "$DB_DUMP"
chmod 600 "$DB_DUMP"

# --- 2. Volume des pièces justificatives (casa_documents_data) -------------
# --user $(id -u):$(id -g) : évite des fichiers appartenant à root sur l'hôte
# (l'image alpine tourne root par défaut).
DOCS_DUMP="$BACKUP_DIR/casa-documents-$STAMP.tar.gz"
docker run --rm --user "$(id -u):$(id -g)" \
    -v casa_documents_data:/data:ro -v "$BACKUP_DIR:/out" alpine \
    tar czf "/out/$(basename "$DOCS_DUMP")" -C /data .
chmod 600 "$DOCS_DUMP"

# --- 3. Rétention ------------------------------------------------------------
find "$BACKUP_DIR" -maxdepth 1 -name 'casa-db-*.sql.gz' -mtime "+$RETENTION_DAYS" -delete
find "$BACKUP_DIR" -maxdepth 1 -name 'casa-documents-*.tar.gz' -mtime "+$RETENTION_DAYS" -delete

DB_SIZE="$(du -h "$DB_DUMP" | cut -f1)"
DOCS_SIZE="$(du -h "$DOCS_DUMP" | cut -f1)"
echo "[$(date -Iseconds)] Sauvegarde OK : $DB_DUMP ($DB_SIZE), $DOCS_DUMP ($DOCS_SIZE)"
