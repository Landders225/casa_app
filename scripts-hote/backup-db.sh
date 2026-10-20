#!/usr/bin/env bash
# =============================================================================
#  CASA — Sauvegarde quotidienne : dump PostgreSQL + volume des pièces
#  justificatives (docs/DEPLOIEMENT.md §12.1-§12.3).
#
#  Tourne sur l'HÔTE (pas dans un conteneur), comme le compte de déploiement
#  (`casa`, membre du groupe `docker` — PAS root : voir le contrôle `docker
#  info` ci-dessous). Cible le conteneur postgres et le volume des pièces par
#  leur nom FIXE (`name: casa` — IDENTIQUE dans docker-compose.prod.yml,
#  docker-compose.test-local.yml ET docker-compose.apache.yml, ADR-28) : ce
#  script ne lit AUCUN .env, ne dépend d'AUCUN override compose, et continue
#  de fonctionner sans modification à travers une bascule de mode.
#
#  Incident 19/10 : disque racine de jupiter plein ~36 h — cause réelle :
#  rotation PAR ÂGE (7 j) insuffisante face à un disque PARTAGÉ (archives +
#  builds Docker + Maarch), combinée à l'absence d'écriture atomique (une
#  sauvegarde tronquée par le disque plein restait indiscernable d'une bonne).
#  Ce script corrige : écriture atomique + vérifiée, contrôle d'espace AVANT
#  d'écrire, rotation PAR NOMBRE (plafond d'espace indépendant de la
#  fréquence), verrou anti-double-exécution, échec toujours visible.
#
#  Usage :
#    scripts-hote/backup-db.sh                    sauvegarde normale
#    scripts-hote/backup-db.sh --verifier-existant audit SANS suppression des
#                                                   archives déjà présentes
#                                                   (intégrité + lisibilité)
#
#  Installation (cron, compte de déploiement, SANS sudo) :
#    crontab -e
#    # ajouter :
#    15 2 * * * /opt/casa/scripts-hote/backup-db.sh >> /srv/backups/backup.log 2>&1
#
#  Variables d'environnement (optionnelles — les 4 dernières existent
#  uniquement pour les tests, scripts-hote/tests/test-backup-db.sh) :
#    BACKUP_DIR             répertoire des sauvegardes (défaut /srv/backups)
#    RETENTION_DB_COUNT      nombre de dumps à garder (défaut 14)
#    RETENTION_DOCS_COUNT    nombre d'archives pièces à garder (défaut 3)
#    POSTGRES_CONTAINER      nom du conteneur postgres (défaut casa-postgres-1)
#    DOCUMENTS_VOLUME        nom du volume des pièces (défaut casa_documents_data)
#
#  Pic d'occupation disque pendant une sauvegarde : jusqu'à
#  RETENTION_DOCS_COUNT archives documents déjà en place (défaut 3, ~1,8 Go
#  chacune observé en prod) + la nouvelle en cours d'écriture ≈ 4 × 1,8 Go
#  ≈ 7,2 Go, PLUS le plancher de 3 Go exigé par le contrôle d'espace — prévoir
#  ~10 Go de marge sur la partition de BACKUP_DIR.
#
#  Codes de sortie :
#    0  succès complet
#    1  usage (argument invalide)
#    2  verrou déjà pris (exécution concurrente)
#    3  docker inaccessible, ou conteneur postgres introuvable
#    4  espace disque insuffisant (rien écrit)
#    5  échec du dump PostgreSQL ou de sa vérification d'intégrité
#    6  échec de l'archive des pièces ou de sa vérification d'intégrité
#
#  Les fonctions ci-dessous sont définies au premier niveau (pas dans main)
#  et le lancement réel est gardé par `[[ "${BASH_SOURCE[0]}" == "$0" ]]` —
#  un `source scripts-hote/backup-db.sh` (depuis les tests,
#  scripts-hote/tests/test-backup-db.sh) charge les fonctions SANS exécuter
#  de sauvegarde : permet de tester `controle_espace` avec des valeurs
#  injectées, sans dépendre d'un système de fichiers réellement petit.
#
#  Restauration + procédure de test sur base JETABLE : voir
#  docs/DEPLOIEMENT.md §12.3.
# =============================================================================
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/srv/backups}"
POSTGRES_CONTAINER="${POSTGRES_CONTAINER:-casa-postgres-1}"
DOCUMENTS_VOLUME="${DOCUMENTS_VOLUME:-casa_documents_data}"
RETENTION_DB_COUNT="${RETENTION_DB_COUNT:-14}"
RETENTION_DOCS_COUNT="${RETENTION_DOCS_COUNT:-3}"

# Marge = ×1,3 (en entiers, pas de virgule flottante en bash) + 200 Mo pour le
# dump (« minuscule » par rapport à l'archive documents) + plancher de 3 Go
# devant rester libres APRÈS la sauvegarde (autres usages du disque partagé).
MARGE_NUM=13
MARGE_DEN=10
HEADROOM_DUMP_OCTETS=$((200 * 1024 * 1024))
PLANCHER_OCTETS=$((3 * 1024 * 1024 * 1024))

# --- Contrôle d'espace, AVANT d'écrire quoi que ce soit ----------------------
# $1 = taille mesurée du volume documents en octets, $2 = espace disponible
# sur BACKUP_DIR en octets. Fonction PURE (pas d'I/O) — c'est la mesure elle-
# même (docker run ... du -sb, df) qui est séparée dans main, pour rester
# testable avec des valeurs injectées.
controle_espace() {
    local docs_size="$1" avail="$2" requis
    requis=$((docs_size * MARGE_NUM / MARGE_DEN + HEADROOM_DUMP_OCTETS))
    if ((avail < requis + PLANCHER_OCTETS)); then
        echo "[$(date -Iseconds)] ERREUR : espace insuffisant sur $BACKUP_DIR — disponible $((avail / 1024 / 1024)) Mo, requis $((requis / 1024 / 1024)) Mo + plancher $((PLANCHER_OCTETS / 1024 / 1024 / 1024)) Go. Aucune sauvegarde existante supprimée pour faire de la place." >&2
        return 4
    fi
    echo "[$(date -Iseconds)] Espace disponible avant sauvegarde : $((avail / 1024 / 1024 / 1024)) Go (requis : $((requis / 1024 / 1024)) Mo + plancher $((PLANCHER_OCTETS / 1024 / 1024 / 1024)) Go)."
}

# --- Rotation PAR NOMBRE, après coup, seulement si la sauvegarde a réussi ----
# Tri lexicographique du NOM (casa-db-YYYYMMDD-HHMMSS.sql.gz s'ordonne déjà
# chronologiquement tel quel) — pas le mtime, insensible à un chown/touch
# ultérieur. Jamais sous 1 exemplaire, quelle que soit la valeur configurée.
# Ne lit JAMAIS le contenu des fichiers (seul le droit d'écriture sur
# BACKUP_DIR est requis pour supprimer) : un reliquat appartenant à un autre
# compte ne bloque donc pas la rotation.
roter() {
    local motif="$1" garder="$2"
    ((garder < 1)) && garder=1
    local -a fichiers
    mapfile -t fichiers < <(find "$BACKUP_DIR" -maxdepth 1 -name "$motif" -printf '%f\n' | sort)
    local total=${#fichiers[@]}
    ((total <= garder)) && return 0
    local a_supprimer=$((total - garder)) i
    for ((i = 0; i < a_supprimer; i++)); do
        echo "[$(date -Iseconds)] Rotation : suppression de ${fichiers[i]}."
        rm -f -- "$BACKUP_DIR/${fichiers[i]}"
    done
}

# --- Mode audit — lecture seule, AUCUNE suppression automatique -------------
verifier_existant() {
    local motif fichier ok=0 corrompus=0 illisibles=0
    for motif in 'casa-db-*.sql.gz' 'casa-documents-*.tar.gz'; do
        while IFS= read -r fichier; do
            [[ -z "$fichier" ]] && continue
            if [[ ! -r "$fichier" ]]; then
                echo "[$(date -Iseconds)] ILLISIBLE par ce compte, non supprimé (probablement un reliquat d'un autre propriétaire) : $fichier"
                illisibles=$((illisibles + 1))
                continue
            fi
            case "$fichier" in
            *.sql.gz)
                if gzip -t "$fichier" 2>/dev/null; then
                    ok=$((ok + 1))
                else
                    echo "[$(date -Iseconds)] CORROMPU, non supprimé : $fichier"
                    corrompus=$((corrompus + 1))
                fi
                ;;
            *.tar.gz)
                if tar -tzf "$fichier" >/dev/null 2>&1; then
                    ok=$((ok + 1))
                else
                    echo "[$(date -Iseconds)] CORROMPU, non supprimé : $fichier"
                    corrompus=$((corrompus + 1))
                fi
                ;;
            esac
        done < <(find "$BACKUP_DIR" -maxdepth 1 -name "$motif" -printf '%p\n' | sort)
    done
    echo "[$(date -Iseconds)] Audit terminé : $ok valide(s), $corrompus corrompu(s), $illisibles illisible(s) — aucune suppression automatique."
}

on_exit() {
    local code=$?
    rm -f -- "$BACKUP_DIR"/*.partial 2>/dev/null || true
    if [[ $code -ne 0 ]]; then
        echo "[$(date -Iseconds)] ÉCHEC (code $code)" >&2
    fi
}

main() {
    local mode="${1:-}"
    if [[ -n "$mode" && "$mode" != "--verifier-existant" ]]; then
        echo "Usage : $0 [--verifier-existant]" >&2
        exit 1
    fi

    mkdir -p "$BACKUP_DIR"
    chmod 700 "$BACKUP_DIR"

    # --- Verrou anti-double-exécution (cron + lancement manuel) -------------
    # AVANT tout nettoyage : une 2e instance refusée ici ne doit RIEN toucher
    # — ni les .partial de la 1re instance (en cours d'écriture), ni le
    # témoin de succès. Elle sort directement, sans trap installé, donc sans
    # effet de bord.
    local lock_file="$BACKUP_DIR/.backup-db.lock"
    exec 9>"$lock_file"
    if ! flock -n 9; then
        echo "[$(date -Iseconds)] ERREUR : une autre exécution est déjà en cours (verrou $lock_file)." >&2
        exit 2
    fi

    # Trap installé SEULEMENT une fois le verrou acquis.
    trap on_exit EXIT

    # Nettoyage des .partial orphelins d'un run précédent (crash sans trap —
    # ex. kill -9). Sûr maintenant : le verrou garantit qu'aucun autre run
    # n'est en train d'écrire l'un de ces fichiers.
    rm -f -- "$BACKUP_DIR"/*.partial 2>/dev/null || true

    if [[ "$mode" == "--verifier-existant" ]]; then
        verifier_existant
        exit 0
    fi

    # --- Sauvegarde normale ---------------------------------------------------
    if ! docker info >/dev/null 2>&1; then
        echo "[$(date -Iseconds)] ERREUR : docker inaccessible pour ce compte (docker info a échoué) — vérifier l'appartenance au groupe docker." >&2
        exit 3
    fi
    if ! docker inspect "$POSTGRES_CONTAINER" >/dev/null 2>&1; then
        echo "[$(date -Iseconds)] ERREUR : conteneur $POSTGRES_CONTAINER introuvable (CASA est-il démarré ?)" >&2
        exit 3
    fi

    local docs_size_octets avail_octets
    docs_size_octets="$(docker run --rm -v "$DOCUMENTS_VOLUME":/data:ro alpine du -sb /data | cut -f1)"
    avail_octets="$(df --output=avail -B1 "$BACKUP_DIR" | tail -1 | tr -d ' ')"
    controle_espace "$docs_size_octets" "$avail_octets" || exit $?

    local stamp
    stamp="$(date +%Y%m%d-%H%M%S)"

    # --- Dump PostgreSQL (compressé), écriture atomique ----------------------
    local db_partial="$BACKUP_DIR/casa-db-$stamp.sql.gz.partial"
    local db_final="$BACKUP_DIR/casa-db-$stamp.sql.gz"
    if ! docker exec "$POSTGRES_CONTAINER" pg_dump -U casa -d casa | gzip >"$db_partial"; then
        echo "[$(date -Iseconds)] ERREUR : échec du dump PostgreSQL (pg_dump | gzip)." >&2
        exit 5
    fi
    chmod 600 "$db_partial"
    if ! gzip -t "$db_partial"; then
        echo "[$(date -Iseconds)] ERREUR : dump PostgreSQL écrit mais intégrité invalide (gzip -t)." >&2
        exit 5
    fi
    mv "$db_partial" "$db_final"
    local db_size
    db_size="$(du -h "$db_final" | cut -f1)"
    echo "[$(date -Iseconds)] Dump PostgreSQL OK : $db_final ($db_size)."

    # --- Volume des pièces justificatives, écriture atomique -----------------
    # Conteneur root (PAS --user $(id -u):$(id -g) : c'est précisément ce qui
    # causait un « Permission denied » — les fichiers du volume appartiennent
    # à l'UID applicatif, pas à l'UID hôte de l'appelant). chown + chmod
    # exécutés DANS le conteneur (root y a le droit), sur le .partial, AVANT
    # le renommage atomique — même mécanisme que le script provisoire,
    # éprouvé en production, appliqué ici au nom temporaire.
    local docs_partial_name="casa-documents-$stamp.tar.gz.partial"
    local docs_partial="$BACKUP_DIR/$docs_partial_name"
    local docs_final="$BACKUP_DIR/casa-documents-$stamp.tar.gz"
    local uidgid
    uidgid="$(id -u):$(id -g)"
    if ! docker run --rm -v "$DOCUMENTS_VOLUME":/data:ro -v "$BACKUP_DIR":/out alpine \
        sh -c "tar czf /out/$docs_partial_name -C /data . && chown $uidgid /out/$docs_partial_name && chmod 600 /out/$docs_partial_name"; then
        echo "[$(date -Iseconds)] ERREUR : échec de l'archive des pièces justificatives (tar)." >&2
        exit 6
    fi
    if ! tar -tzf "$docs_partial" >/dev/null; then
        echo "[$(date -Iseconds)] ERREUR : archive des pièces écrite mais intégrité invalide (tar -tzf)." >&2
        exit 6
    fi
    mv "$docs_partial" "$docs_final"
    local docs_size
    docs_size="$(du -h "$docs_final" | cut -f1)"
    echo "[$(date -Iseconds)] Archive pièces OK : $docs_final ($docs_size)."

    roter 'casa-db-*.sql.gz' "$RETENTION_DB_COUNT"
    roter 'casa-documents-*.tar.gz' "$RETENTION_DOCS_COUNT"

    # --- Témoin de succès (pour une alerte externe, non construite ici) ------
    date -Iseconds >"$BACKUP_DIR/.derniere-sauvegarde-ok"

    echo "[$(date -Iseconds)] Sauvegarde OK : $db_final ($db_size), $docs_final ($docs_size)"
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi
