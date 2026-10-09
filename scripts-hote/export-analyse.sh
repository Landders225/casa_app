#!/usr/bin/env bash
# =============================================================================
#  CASA — Export d'analyse administratif pseudonymisé (casa:export-analyse,
#  ADR-38) : lance la commande dans le conteneur backend, récupère le fichier
#  produit vers l'hôte AVEC VÉRIFICATION D'INTÉGRITÉ, puis le supprime du
#  conteneur. docs/DEPLOIEMENT.md §17.
#
#  Tourne sur jupiter, comme le compte "casa" (PAS root) — mêmes chemins et
#  mêmes fichiers compose que §12.6bis (mode Apache, alias `dca` du compte de
#  déploiement) : /opt/casa/.env.production + docker-compose.yml +
#  docker-compose.prod.yml + docker-compose.apache.yml. Ce script ne lit PAS
#  l'alias `dca` lui-même (les alias du shell interactif ne survivent pas à un
#  script non-interactif) : il reconstruit la même invocation explicitement.
#
#  Usage :
#    scripts-hote/export-analyse.sh <email d'un compte administrateur>
#
#  Demande écrite (mail) de l'administration requise avant chaque
#  extraction ; accord du DSI selon l'ADR-38 — ce script ne le vérifie pas
#  lui-même : c'est un acte humain préalable, hors de son périmètre.
#
#  Aucune donnée de candidat n'est affichée par ce script dans son
#  récapitulatif final (étape 6) : uniquement un nom de fichier (horodaté,
#  générique), un nombre de lignes et une empreinte SHA-256. En cas d'échec de
#  la commande elle-même (ville non classée, fuite détectée), la sortie de
#  casa:export-analyse est répercutée telle quelle — elle ne contient par
#  conception que des valeurs de ville/catégories, jamais un identifiant
#  individuel (cf. ADR-38) — jamais de fichier copié ni supprimé dans ce cas.
# =============================================================================
set -euo pipefail

if [[ $# -ne 1 || -z "$1" ]]; then
    echo "Usage : $0 <email d'un compte administrateur>" >&2
    exit 1
fi
OPERATEUR="$1"

CASA_DIR="/opt/casa"
COMPOSE=(docker compose --env-file "$CASA_DIR/.env.production" \
    -f "$CASA_DIR/docker-compose.yml" \
    -f "$CASA_DIR/docker-compose.prod.yml" \
    -f "$CASA_DIR/docker-compose.apache.yml")

EXPORTS_TMP="$HOME/exports-tmp"
mkdir -p "$EXPORTS_TMP"
chmod 700 "$EXPORTS_TMP"

# --- 1. Exécution réelle dans le conteneur backend --------------------------
echo "[$(date -Iseconds)] Lancement de casa:export-analyse (opérateur : $OPERATEUR)..."
if ! SORTIE="$("${COMPOSE[@]}" exec -T backend php artisan casa:export-analyse --operateur="$OPERATEUR" --no-ansi 2>&1)"; then
    echo "$SORTIE" >&2
    echo "[$(date -Iseconds)] ERREUR : la commande a échoué (voir ci-dessus) — rien copié, rien supprimé." >&2
    exit 1
fi
echo "$SORTIE"

# --- 2. Extraction du chemin, du SHA-256 et du nombre de lignes affichés ----
CHEMIN_CONTENEUR="$(sed -nE 's/^Fichier écrit \(hors zone publique, chmod 600\) : (.+)$/\1/p' <<< "$SORTIE")"
SHA256_ATTENDU="$(sed -nE 's/^SHA-256 : ([0-9a-f]{64})$/\1/p' <<< "$SORTIE")"
NB_LIGNES="$(sed -nE 's/^([0-9]+) ligne\(s\), [0-9]+ colonne\(s\)\.$/\1/p' <<< "$SORTIE")"

if [[ -z "$CHEMIN_CONTENEUR" || -z "$SHA256_ATTENDU" ]]; then
    echo "[$(date -Iseconds)] ERREUR : impossible d'extraire le chemin ou le SHA-256 de la sortie de la commande ci-dessus." >&2
    exit 1
fi
NOM_FICHIER="$(basename "$CHEMIN_CONTENEUR")"

# --- 3. Copie vers l'hôte, droits 600 (umask scopé à CETTE SEULE commande, --
#        via un sous-shell — sans effet sur le umask du shell appelant) -----
#        Méthode validée en production : `exec ... cat` plutôt que
#        `docker compose cp` (flux canalisé directement vers le fichier de
#        destination, déjà sous le umask scopé ci-dessus).
( umask 077; "${COMPOSE[@]}" exec -T backend cat "$CHEMIN_CONTENEUR" > "$EXPORTS_TMP/$NOM_FICHIER" )
chmod 600 "$EXPORTS_TMP/$NOM_FICHIER"

# --- 4. Vérification d'intégrité AVANT toute suppression -------------------
SHA256_COPIE="$(sha256sum "$EXPORTS_TMP/$NOM_FICHIER" | cut -d' ' -f1)"
if [[ "$SHA256_COPIE" != "$SHA256_ATTENDU" ]]; then
    echo "[$(date -Iseconds)] ERREUR : SHA-256 différent après copie — le fichier n'est PAS supprimé du conteneur, à ré-examiner avant toute nouvelle tentative." >&2
    echo "  attendu (annoncé par la commande) : $SHA256_ATTENDU" >&2
    echo "  obtenu  (copie sur l'hôte)         : $SHA256_COPIE" >&2
    exit 1
fi

# --- 5. Suppression du fichier dans le conteneur (copie déjà vérifiée) -----
"${COMPOSE[@]}" exec -T backend rm "$CHEMIN_CONTENEUR"

# --- 6. Récapitulatif — AUCUNE donnée de candidat ---------------------------
echo ""
echo "Fichier récupéré : $EXPORTS_TMP/$NOM_FICHIER"
echo "Lignes : ${NB_LIGNES:-?}"
echo "SHA-256 (vérifié identique) : $SHA256_COPIE"
echo ""
echo "À lancer DEPUIS LE POSTE DE L'OPÉRATEUR — PAS sur jupiter :"
echo "  scp $(whoami)@$(hostname):$EXPORTS_TMP/$NOM_FICHIER ."
echo "  (si le compte casa n'a pas d'accès SSH, remplacer casa@ par le compte habituel, ex. root@)"
echo ""
echo "⚠️  Une fois le fichier remis à l'opérateur : supprimer ce répertoire sur jupiter —"
echo "    rm -rf $EXPORTS_TMP"
echo "    Ne pas le laisser en place au-delà de la remise."
