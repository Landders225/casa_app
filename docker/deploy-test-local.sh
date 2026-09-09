#!/usr/bin/env bash
# =============================================================================
#  CASA — Déploiement MODE TEST LOCAL HTTP DIRECT (Lot 11).
#
#  Monte CASA joignable en HTTP à http://<IP-serveur>:8090 (pas d'Apache, pas de
#  HTTPS, pas de domaine). Étape de validation AVANT le mode Apache (§ 14).
#
#  À lancer par le compte du groupe `docker` (PAS root), depuis la racine du
#  dépôt cloné :
#      CASA_SERVER_IP=172.30.4.200 bash docker/deploy-test-local.sh
#
#  Variables :
#      CASA_SERVER_IP   (REQUIS) IP par laquelle on tapera le navigateur
#      CASA_HTTP_PORT   défaut 8090
#      CASA_SUBNET      défaut 172.31.243.0/24  (= TRUSTED_PROXIES)
#
#  ⚠️ NE PAS exposer sur Internet (cookies non-Secure). Idempotent.
#     NE crée PAS le premier admin (acte interactif — rappelé à la fin).
# =============================================================================
set -euo pipefail

CASA_SERVER_IP="${CASA_SERVER_IP:?Définir CASA_SERVER_IP (ex: CASA_SERVER_IP=172.30.4.200)}"
CASA_HTTP_PORT="${CASA_HTTP_PORT:-8090}"
CASA_SUBNET="${CASA_SUBNET:-172.31.243.0/24}"

cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

DC=(docker compose --env-file .env.test-local
    -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.test-local.yml)

say() { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }

# --- 0. Contrôles -------------------------------------------------------
say "Contrôles"
[ "$(id -u)" -ne 0 ] || { echo "Lancer SANS sudo (compte du groupe docker)."; exit 1; }
docker compose version >/dev/null || { echo "Docker / plugin compose absent"; exit 1; }
if ss -tlnH "sport = :${CASA_HTTP_PORT}" 2>/dev/null | grep -q .; then
    echo "⚠️  Le port ${CASA_HTTP_PORT} est déjà pris — choisir un autre CASA_HTTP_PORT."; exit 1
fi
# DNS Docker : le build (npm/composer/apk) doit résoudre les registres publics.
if ! docker run --rm alpine sh -c 'nslookup registry.npmjs.org' >/dev/null 2>&1; then
    echo "⚠️  DNS des conteneurs Docker KO — le build va échouer. Corriger :"
    echo "      echo '{ \"dns\": [\"8.8.8.8\", \"1.1.1.1\"] }' | sudo tee /etc/docker/daemon.json"
    echo "      sudo systemctl restart docker"
    echo "    (docs/DEPLOIEMENT.md § 13). Entrée pour tenter quand même, Ctrl-C pour arrêter."
    read -r _
fi

# --- 1. Fichiers .env.test-local -------------------------------------
say "Configuration (.env.test-local)"
if [ ! -f .env.test-local ]; then
    cp .env.test-local.example .env.test-local
    PGPW="$(openssl rand -base64 24 | tr -d '\n' | tr '/+=' 'xyz')"
    sed -i "s|^POSTGRES_PASSWORD=.*|POSTGRES_PASSWORD=${PGPW}|" .env.test-local
    sed -i "s|^CASA_HTTP_PORT=.*|CASA_HTTP_PORT=${CASA_HTTP_PORT}|" .env.test-local
    sed -i "s|^CASA_SUBNET=.*|CASA_SUBNET=${CASA_SUBNET}|" .env.test-local
    echo "  → .env.test-local créé (mot de passe Postgres généré)"
else
    PGPW="$(grep -E '^POSTGRES_PASSWORD=' .env.test-local | cut -d= -f2-)"
    echo "  → .env.test-local existant conservé"
fi

if [ ! -f backend/.env.test-local ]; then
    cp backend/.env.test-local.example backend/.env.test-local
    APPKEY="$("${DC[@]}" run --rm --no-deps --entrypoint php backend artisan key:generate --show 2>/dev/null | tail -1)"
    sed -i "s|^APP_KEY=.*|APP_KEY=${APPKEY}|"                                          backend/.env.test-local
    sed -i "s|^APP_URL=.*|APP_URL=http://${CASA_SERVER_IP}:${CASA_HTTP_PORT}|"         backend/.env.test-local
    sed -i "s|^SESSION_DOMAIN=.*|SESSION_DOMAIN=${CASA_SERVER_IP}|"                    backend/.env.test-local
    sed -i "s|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=${CASA_SERVER_IP}:${CASA_HTTP_PORT}|" backend/.env.test-local
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${PGPW}|"                                    backend/.env.test-local
    sed -i "s|^TRUSTED_PROXIES=.*|TRUSTED_PROXIES=${CASA_SUBNET}|"                     backend/.env.test-local
    echo "  → backend/.env.test-local créé (APP_KEY généré ; APP_URL=http://${CASA_SERVER_IP}:${CASA_HTTP_PORT} ; SESSION_SECURE_COOKIE=false)"
else
    echo "  → backend/.env.test-local existant conservé"
fi
"${DC[@]}" config -q && echo "  → compose OK"

# --- 2. Build + démarrage --------------------------------------------
say "Build + démarrage (quelques minutes au premier lancement)"
"${DC[@]}" up -d --build
echo -n "  Attente des services healthy "
for _ in $(seq 1 72); do
    n=$("${DC[@]}" ps --format '{{.Health}}' | grep -c healthy || true)
    [ "$n" -ge 4 ] && { echo " OK ($n/4)"; break; }
    echo -n "."; sleep 5
done
"${DC[@]}" ps

REAL_SUBNET="$(docker network inspect casa_casa -f '{{(index .IPAM.Config 0).Subnet}}' 2>/dev/null || echo '?')"
[ "$REAL_SUBNET" = "$CASA_SUBNET" ] \
  && echo "  ✓ réseau casa_casa = ${CASA_SUBNET} (= TRUSTED_PROXIES)" \
  || { echo "  ⚠️  réseau casa_casa = ${REAL_SUBNET} ≠ ${CASA_SUBNET} — relancer avec CASA_SUBNET=<libre> après « ${DC[*]} down »."; exit 1; }

# --- 3. Base de données --------------------------------------------
say "Migrations + référentiel (sans comptes démo)"
"${DC[@]}" exec -T backend php artisan migrate --force
"${DC[@]}" exec -T backend php artisan casa:seed-referentiel

# --- 4. Vérifications ---------------------------------------------
say "Vérifications"
curl -fsS "http://127.0.0.1:${CASA_HTTP_PORT}/up" >/dev/null && echo "  ✓ /up répond (local)"
echo "  ✓ SPA : HTTP $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:${CASA_HTTP_PORT}/)"
# Anti-usurpation : 65 requêtes /api/health avec un X-Forwarded-For forgé tournant
# → un 429 DOIT apparaître (le limiteur key sur la vraie IP, pas l'en-tête).
codes="$(for i in $(seq 1 65); do
  curl -s -o /dev/null -w '%{http_code} ' -H "X-Forwarded-For: 10.$i.$i.$i" \
    "http://127.0.0.1:${CASA_HTTP_PORT}/api/health"; done)"
if grep -q 429 <<<"$codes"; then
  echo "  ✓ rate-limiting keyé sur la vraie IP (X-Forwarded-For forgé ignoré)"
else
  echo "  ⚠️  aucun 429 sur 65 requêtes à XFF tournant → usurpation d'IP possible ! Revoir casa.test.conf / TRUSTED_PROXIES."
fi

cat <<EOF

──────────────────────────────────────────────────────────────────────────────
 CASA (mode TEST) est joignable :  http://${CASA_SERVER_IP}:${CASA_HTTP_PORT}

 1. Comptes de l'équipe (interactif — docs/DEPLOIEMENT.md § 9) :
      ${DC[*]} exec backend php artisan casa:create-admin admin@exemple.ci
      ${DC[*]} exec backend php artisan casa:create-membre eval@exemple.ci   # un par évaluateur
 2. Ouvrir http://${CASA_SERVER_IP}:${CASA_HTTP_PORT} dans un navigateur et
    se connecter (le cookie de session se pose bien en HTTP, Secure=false).
 3. Quand c'est validé → passer au mode Apache + HTTPS (docs/DEPLOIEMENT.md § 14) :
      ${DC[*]} down
      … puis suivre § 14 (SESSION_SECURE_COOKIE=true, vrai domaine).
──────────────────────────────────────────────────────────────────────────────
EOF
