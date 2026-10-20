#!/usr/bin/env bash
# =============================================================================
#  Tests de scripts-hote/backup-db.sh — AUCUN accès à la prod, AUCUNE donnée
#  réelle. Crée un conteneur postgres jetable + un volume Docker jetable,
#  utilise des répertoires temporaires comme BACKUP_DIR.
#
#  Ne tourne PAS correctement sous Git Bash Windows (flock/stat/chown/droits
#  Unix non fiables) : à lancer DANS un conteneur Linux ayant accès au démon
#  Docker de l'hôte (testé réellement ainsi, Docker Desktop Windows,
#  2026-10-20 — 29 assertions, 0 échec) :
#
#    REPO="$(pwd)"
#    docker run --rm \
#      -v /var/run/docker.sock:/var/run/docker.sock \
#      -v "$REPO":"$REPO" -w "$REPO" \
#      -e TMPDIR="$REPO/.test-tmp" \
#      docker:27-cli sh -c '
#        mkdir -p "$TMPDIR"
#        apk add --no-cache bash util-linux coreutils tar gzip findutils >/dev/null &&
#        bash scripts-hote/tests/test-backup-db.sh'
#
#  ⚠️ IMPORTANT (Docker-outside-of-Docker) : le bind-mount DOIT utiliser le
#  MÊME chemin des deux côtés (`-v "$REPO":"$REPO"`), PAS un nom différent
#  comme `/repo` ou `/hosttmp`. Ce script lance lui-même des conteneurs
#  SIBLINGS (pas des enfants) via le socket partagé — le démon Docker Desktop
#  résout les chemins `-v` de CES conteneurs sibling comme des chemins du
#  VRAI hôte Windows (`/c/Users/...`), pas comme des chemins internes au
#  conteneur de test. Un `BACKUP_DIR` sous `/repo/...` ou `/hosttmp/...`
#  échoue silencieusement dans l'étape d'archive (« Cannot open: No such
#  file or directory ») car ce chemin n'existe pas pour le démon. `TMPDIR`
#  réglé sur `$REPO/.test-tmp` fait que `mktemp -d` produit des chemins déjà
#  dans la bonne forme.
#
#  Ce que ces tests COUVRENT : écriture atomique + vérification d'intégrité,
#  rotation par nombre (motif strict, fichiers hors motif préservés), verrou
#  anti-double-exécution, docker inaccessible, échec du dump après la
#  vérification d'existence du conteneur, mode --verifier-existant sur une
#  archive tronquée, propriété/permissions des fichiers produits, logique de
#  décision du contrôle d'espace (fonction `controle_espace`, valeurs
#  injectées).
#
#  Ce qu'ils NE couvrent PAS : une pénurie d'espace disque RÉELLE au niveau
#  du système de fichiers (nécessiterait un système de fichiers réellement
#  petit — tmpfs/loopback — non réalisable proprement depuis un conteneur
#  ciblant un BACKUP_DIR qui est un chemin HÔTE bind-monté ; seule la
#  logique de décision est testée, avec des tailles injectées). L'essai en
#  conditions réelles (espace réellement bas) se fait à la main sur jupiter.
# =============================================================================
set -uo pipefail

ICI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="$ICI/../backup-db.sh"
ECHECS=0
REUSSIS=0

ok() {
    REUSSIS=$((REUSSIS + 1))
    echo "  OK   $1"
}
ko() {
    ECHECS=$((ECHECS + 1))
    echo "  FAIL $1"
}
assert_eq() {
    local attendu="$1" obtenu="$2" libelle="$3"
    if [[ "$attendu" == "$obtenu" ]]; then
        ok "$libelle (= $obtenu)"
    else
        ko "$libelle (attendu=$attendu obtenu=$obtenu)"
    fi
}
assert_true() {
    if eval "$1"; then
        ok "$2"
    else
        ko "$2"
    fi
}
assert_false() {
    if eval "$1"; then
        ko "$2"
    else
        ok "$2"
    fi
}

echo "=== Préparation des fixtures jetables ==="
PG_NAME="test-backup-pg-$$"
FAKE_NAME="test-backup-fake-$$"
VOL_NAME="test-backup-docs-$$"

nettoyer_fixtures() {
    docker rm -f "$PG_NAME" "$FAKE_NAME" >/dev/null 2>&1 || true
    docker volume rm "$VOL_NAME" >/dev/null 2>&1 || true
}
trap nettoyer_fixtures EXIT

docker run -d --name "$PG_NAME" -e POSTGRES_PASSWORD=test -e POSTGRES_USER=casa -e POSTGRES_DB=casa postgres:16-alpine >/dev/null
docker run -d --name "$FAKE_NAME" alpine sleep 600 >/dev/null
docker volume create "$VOL_NAME" >/dev/null
docker run --rm -v "$VOL_NAME":/data alpine sh -c \
    "mkdir -p /data && echo jeton1 > /data/a.txt && echo jeton2 > /data/b.txt" >/dev/null

echo "Attente de postgres..."
for _ in $(seq 1 30); do
    docker exec "$PG_NAME" pg_isready -U casa >/dev/null 2>&1 && break
    sleep 1
done
docker exec "$PG_NAME" pg_isready -U casa >/dev/null 2>&1 || {
    echo "postgres n'a pas démarré à temps — abandon." >&2
    exit 1
}
echo "Fixtures prêtes : postgres=$PG_NAME conteneur-non-pg=$FAKE_NAME volume=$VOL_NAME"
echo

# =============================================================================
echo "=== Test 1 : succès normal ==="
T1="$(mktemp -d)"
OUT1="$(BACKUP_DIR="$T1" POSTGRES_CONTAINER="$PG_NAME" DOCUMENTS_VOLUME="$VOL_NAME" bash "$SCRIPT" 2>&1)"
CODE1=$?
echo "$OUT1" | sed 's/^/  | /'
assert_eq "0" "$CODE1" "code de sortie"
DB_FILE="$(find "$T1" -maxdepth 1 -name 'casa-db-*.sql.gz')"
DOCS_FILE="$(find "$T1" -maxdepth 1 -name 'casa-documents-*.tar.gz')"
assert_true '[[ -n "$DB_FILE" ]]' "dump DB présent"
assert_true '[[ -n "$DOCS_FILE" ]]' "archive documents présente"
assert_true 'gzip -t "$DB_FILE"' "dump DB intègre (gzip -t)"
assert_true 'tar -tzf "$DOCS_FILE" >/dev/null' "archive documents intègre (tar -tzf)"
assert_true '[[ -f "$T1/.derniere-sauvegarde-ok" ]]' "témoin de succès écrit"
assert_eq "700" "$(stat -c '%a' "$T1")" "BACKUP_DIR en 700"
assert_eq "600" "$(stat -c '%a' "$DB_FILE")" "dump DB en 600"
assert_eq "600" "$(stat -c '%a' "$DOCS_FILE")" "archive documents en 600"
assert_eq "$(id -u)" "$(stat -c '%u' "$DOCS_FILE")" "archive documents appartient à l'appelant (pas root)"
assert_false 'find "$T1" -maxdepth 1 -name "*.partial" | grep -q .' "aucun .partial résiduel"
rm -rf "$T1"
echo

# =============================================================================
echo "=== Test 2 : rotation par nombre, motif strict ==="
T2="$(mktemp -d)"
# 5 fausses archives DB valides (gzip réel), horodatages croissants.
for i in 1 2 3 4 5; do
    echo "contenu-$i" | gzip >"$T2/casa-db-2026010$i-000000.sql.gz"
done
# 4 fausses archives documents valides (tar réel).
for i in 1 2 3 4; do
    mkdir -p "$T2/src$i" && echo "x" >"$T2/src$i/f"
    tar czf "$T2/casa-documents-2026010$i-000000.tar.gz" -C "$T2/src$i" .
done
# Fichier HORS motif — ne doit jamais être touché.
echo "secret" >"$T2/env-production-20260101.gpg"
OUT2="$(BACKUP_DIR="$T2" POSTGRES_CONTAINER="$PG_NAME" DOCUMENTS_VOLUME="$VOL_NAME" RETENTION_DB_COUNT=2 RETENTION_DOCS_COUNT=1 bash "$SCRIPT" 2>&1)"
CODE2=$?
echo "$OUT2" | sed 's/^/  | /'
assert_eq "0" "$CODE2" "code de sortie"
# 5 pré-existantes + 1 nouvelle = 6, garder 2 -> 4 supprimées -> il doit en
# rester 2 (les 2 plus récentes par nom) + la toute nouvelle vient d'être
# créée donc comptée aussi : total gardé = RETENTION_DB_COUNT = 2.
NB_DB="$(find "$T2" -maxdepth 1 -name 'casa-db-*.sql.gz' | wc -l)"
NB_DOCS="$(find "$T2" -maxdepth 1 -name 'casa-documents-*.tar.gz' | wc -l)"
assert_eq "2" "$NB_DB" "nombre de dumps DB restants = RETENTION_DB_COUNT"
assert_eq "1" "$NB_DOCS" "nombre d'archives documents restantes = RETENTION_DOCS_COUNT"
assert_true '[[ -f "$T2/env-production-20260101.gpg" ]]' "fichier hors motif (.gpg) préservé par la rotation"
assert_false '[[ -f "$T2/casa-db-20260101-000000.sql.gz" ]]' "la plus ancienne archive DB a bien été supprimée"
rm -rf "$T2"
echo

# =============================================================================
echo "=== Test 3 : contrôle d'espace — fonction controle_espace() isolée ==="
echo "(couvre la logique de décision avec des valeurs injectées — PAS une pénurie réelle de disque)"
# shellcheck source=/dev/null
source "$SCRIPT"
# backup-db.sh impose `set -euo pipefail` à CE shell en se sourçant — annulé
# ici pour retrouver le mode résilient de ce script de test (qui continue
# après un échec d'assertion pour toutes les faire tourner).
set +e
OUT3="$(controle_espace 2000000000 1000000000 2>&1)"
CODE3=$?
echo "$OUT3" | sed 's/^/  | /'
assert_eq "4" "$CODE3" "refuse quand espace disponible < requis + plancher"
OUT3B="$(controle_espace 100000000 10000000000 2>&1)"
CODE3B=$?
echo "$OUT3B" | sed 's/^/  | /'
assert_eq "0" "$CODE3B" "accepte quand l'espace est largement suffisant"
echo

# =============================================================================
echo "=== Test 4 : archive tronquée détectée par --verifier-existant, non supprimée ==="
T4="$(mktemp -d)"
chmod 700 "$T4"
head -c 50 /bin/sh >"$T4/casa-documents-20260101-000000.tar.gz" 2>/dev/null || dd if=/dev/zero of="$T4/casa-documents-20260101-000000.tar.gz" bs=1 count=50 2>/dev/null
mkdir -p "$T4/src" && echo x >"$T4/src/f"
tar czf "$T4/casa-documents-20260102-000000.tar.gz" -C "$T4/src" .
OUT4="$(BACKUP_DIR="$T4" bash "$SCRIPT" --verifier-existant 2>&1)"
CODE4=$?
echo "$OUT4" | sed 's/^/  | /'
assert_eq "0" "$CODE4" "code de sortie du mode audit"
assert_true 'echo "$OUT4" | grep -q "CORROMPU.*20260101"' "l'archive tronquée est signalée CORROMPU"
assert_true '[[ -f "$T4/casa-documents-20260101-000000.tar.gz" ]]' "l'archive corrompue n'est PAS supprimée (audit seul)"
assert_true '[[ -f "$T4/casa-documents-20260102-000000.tar.gz" ]]' "l'archive valide reste présente"
rm -rf "$T4"
echo

# =============================================================================
echo "=== Test 5 : double exécution bloquée par le verrou ==="
T5="$(mktemp -d)"
chmod 700 "$T5"
exec 8>"$T5/.backup-db.lock"
flock -n 8
OUT5="$(BACKUP_DIR="$T5" POSTGRES_CONTAINER="$PG_NAME" DOCUMENTS_VOLUME="$VOL_NAME" bash "$SCRIPT" 2>&1)"
CODE5=$?
echo "$OUT5" | sed 's/^/  | /'
assert_eq "2" "$CODE5" "refuse quand le verrou est déjà pris"
assert_true 'echo "$OUT5" | grep -qi "déjà en cours"' "message de refus explicite"
flock -u 8
exec 8>&-
rm -rf "$T5"
echo

# =============================================================================
echo "=== Test 6 : docker inaccessible ==="
T6="$(mktemp -d)"
OUT6="$(BACKUP_DIR="$T6" DOCKER_HOST=unix:///tmp/inexistant-$$.sock bash "$SCRIPT" 2>&1)"
CODE6=$?
echo "$OUT6" | sed 's/^/  | /'
assert_eq "3" "$CODE6" "refuse quand docker est inaccessible"
rm -rf "$T6"
echo

# =============================================================================
echo "=== Test 7 : le conteneur postgres existe (passe la vérification), mais pg_dump échoue ==="
T7="$(mktemp -d)"
chmod 700 "$T7"
OUT7="$(BACKUP_DIR="$T7" POSTGRES_CONTAINER="$FAKE_NAME" DOCUMENTS_VOLUME="$VOL_NAME" bash "$SCRIPT" 2>&1)"
CODE7=$?
echo "$OUT7" | sed 's/^/  | /'
assert_eq "5" "$CODE7" "code de sortie dédié au dump (pas un code arbitraire de la pipeline)"
assert_false 'find "$T7" -maxdepth 1 -name "casa-db-*.sql.gz" | grep -q .' "aucun dump final écrit"
assert_false 'find "$T7" -maxdepth 1 -name "*.partial" | grep -q .' "le .partial a été nettoyé par le trap"
assert_true 'echo "$OUT7" | grep -q "ÉCHEC (code 5)"' "ligne ÉCHEC avec le bon code dans le log"
rm -rf "$T7"
echo

echo "=== Résultat : $REUSSIS réussi(s), $ECHECS échec(s) ==="
[[ $ECHECS -eq 0 ]]
