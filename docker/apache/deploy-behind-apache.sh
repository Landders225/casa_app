#!/usr/bin/env bash
# =============================================================================
#  CASA — Déploiement « derrière un Apache existant » (Lot 11).
#
#  Automatise docs/DEPLOIEMENT.md § 13 sur un serveur qui héberge déjà d'autres
#  applications (Apache en façade sur 80/443). CASA tourne en HTTP local ; Apache
#  proxifie.
#
#  Usage (depuis la racine du dépôt cloné, ex. /opt/casa) :
#      sudo CASA_DOMAIN=casa.mon-domaine.ci CASA_ADMIN_EMAIL=admin@mon-domaine.ci \
#           bash docker/apache/deploy-behind-apache.sh
#
#  Variables (toutes optionnelles sauf indication) :
#      CASA_DOMAIN        (REQUIS) sous-domaine servi par Apache
#      CASA_ADMIN_EMAIL   e-mail ServerAdmin + Let's Encrypt (défaut: admin@$CASA_DOMAIN)
#      CASA_HTTP_PORT     port local de nginx CASA           (défaut: 8090)
#      CASA_BIND_ADDR     interface d'écoute                 (défaut: 127.0.0.1)
#      RUN_CERTBOT=1      lance `certbot certonly --apache` à la fin
#
#  Idempotent : relançable. Ne touche jamais aux autres vhosts / conteneurs.
#  NE crée PAS le premier compte admin (acte interactif) : voir la fin du script.
# =============================================================================
set -euo pipefail

CASA_DOMAIN="${CASA_DOMAIN:?Définir CASA_DOMAIN (ex: CASA_DOMAIN=casa.mon-domaine.ci)}"
CASA_ADMIN_EMAIL="${CASA_ADMIN_EMAIL:-admin@${CASA_DOMAIN}}"
CASA_HTTP_PORT="${CASA_HTTP_PORT:-8090}"
CASA_BIND_ADDR="${CASA_BIND_ADDR:-127.0.0.1}"
RUN_CERTBOT="${RUN_CERTBOT:-0}"

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_DIR"

# Compte non-root qui pilotera Docker ensuite (membre du groupe docker).
DEPLOY_USER="${SUDO_USER:-$(id -un)}"

DC=(docker compose --env-file .env.production
    -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.apache.yml)

say() { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }

# --- 0. Contrôles -----------------------------------------------------------
say "Contrôles"
command -v docker >/dev/null || { echo "Docker absent"; exit 1; }
docker compose version >/dev/null || { echo "Plugin compose absent"; exit 1; }
command -v apache2ctl >/dev/null || { echo "Apache absent"; exit 1; }
if ss -tlnH "sport = :${CASA_HTTP_PORT}" | grep -q .; then
    echo "⚠️  Le port ${CASA_HTTP_PORT} est déjà utilisé — choisir un autre CASA_HTTP_PORT."; exit 1
fi
chown -R "$DEPLOY_USER":"$DEPLOY_USER" "$REPO_DIR"

# --- 1. Fichiers .env.production ------------------------------------------
say "Configuration (.env.production)"
if [ ! -f .env.production ]; then
    cp .env.production.example .env.production
    PGPW="$(openssl rand -base64 24 | tr -d '\n' | tr '/+=' 'xyz')"
    sed -i "s|^POSTGRES_PASSWORD=.*|POSTGRES_PASSWORD=${PGPW}|" .env.production
    sed -i "s|^CASA_HTTP_PORT=.*|CASA_HTTP_PORT=${CASA_HTTP_PORT}|" .env.production
    sed -i "s|^CASA_BIND_ADDR=.*|CASA_BIND_ADDR=${CASA_BIND_ADDR}|" .env.production
    echo "  → .env.production créé (mot de passe Postgres généré)"
else
    PGPW="$(grep -E '^POSTGRES_PASSWORD=' .env.production | cut -d= -f2-)"
    echo "  → .env.production existant conservé"
fi

if [ ! -f backend/.env.production ]; then
    cp backend/.env.production.example backend/.env.production
    APPKEY="$("${DC[@]}" run --rm --no-deps --entrypoint php backend artisan key:generate --show 2>/dev/null | tail -1)"
    sed -i "s|^APP_KEY=.*|APP_KEY=${APPKEY}|"                                   backend/.env.production
    sed -i "s|^APP_URL=.*|APP_URL=https://${CASA_DOMAIN}|"                      backend/.env.production
    sed -i "s|^SESSION_DOMAIN=.*|SESSION_DOMAIN=${CASA_DOMAIN}|"               backend/.env.production
    sed -i "s|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=${CASA_DOMAIN}|" backend/.env.production
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${PGPW}|"                            backend/.env.production
    echo "  → backend/.env.production créé (APP_KEY généré, domaine ${CASA_DOMAIN})"
else
    echo "  → backend/.env.production existant conservé"
fi
sudo -u "$DEPLOY_USER" "${DC[@]}" config -q && echo "  → compose OK"

# --- 2. Démarrage de CASA (HTTP local) ----------------------------------
say "Build + démarrage de CASA (peut prendre quelques minutes)"
sudo -u "$DEPLOY_USER" "${DC[@]}" up -d --build

echo -n "  Attente des services healthy "
for _ in $(seq 1 60); do
    n=$(sudo -u "$DEPLOY_USER" "${DC[@]}" ps --format '{{.Health}}' | grep -c healthy || true)
    [ "$n" -ge 4 ] && { echo " OK ($n/4)"; break; }
    echo -n "."; sleep 5
done
sudo -u "$DEPLOY_USER" "${DC[@]}" ps

# --- 3. Base de données -------------------------------------------------
say "Migrations + référentiel (sans comptes démo)"
sudo -u "$DEPLOY_USER" "${DC[@]}" exec -T backend php artisan migrate --force
sudo -u "$DEPLOY_USER" "${DC[@]}" exec -T backend php artisan casa:seed-referentiel

# --- 4. VirtualHost Apache --------------------------------------------
say "Configuration Apache"
a2enmod proxy proxy_http headers rewrite ssl >/dev/null
VHOST=/etc/apache2/sites-available/casa.conf
sed -e "s|casa\.exemple\.ci|${CASA_DOMAIN}|g" \
    -e "s|admin@exemple\.ci|${CASA_ADMIN_EMAIL}|g" \
    -e "s|http://127\.0\.0\.1:8090|http://${CASA_BIND_ADDR}:${CASA_HTTP_PORT}|g" \
    docker/apache/casa.vhost.conf > "$VHOST"
a2ensite casa >/dev/null
apache2ctl configtest
systemctl reload apache2
echo "  → vhost casa.conf installé et activé (domaine ${CASA_DOMAIN})"

# --- 5. Vérifications --------------------------------------------------
say "Vérifications"
curl -fsS "http://${CASA_BIND_ADDR}:${CASA_HTTP_PORT}/up" >/dev/null \
    && echo "  ✓ CASA répond en local (${CASA_BIND_ADDR}:${CASA_HTTP_PORT}/up)"
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: ${CASA_DOMAIN}" "http://127.0.0.1/up")
echo "  ✓ via Apache (Host: ${CASA_DOMAIN}) : HTTP ${code}"

# --- 6. Certificat (optionnel) --------------------------------------
if [ "$RUN_CERTBOT" = "1" ]; then
    say "Certificat Let's Encrypt"
    command -v certbot >/dev/null || apt-get install -y certbot
    certbot certonly --apache -d "$CASA_DOMAIN" -m "$CASA_ADMIN_EMAIL" --agree-tos --no-eff-email
    mkdir -p /etc/letsencrypt/renewal-hooks/deploy
    echo 'systemctl reload apache2' > /etc/letsencrypt/renewal-hooks/deploy/reload-apache.sh
    chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-apache.sh
    systemctl reload apache2
    echo "  → HTTPS actif, :80 redirige vers :443"
fi

# Le compte de déploiement reprend la main sur tout le dépôt (fichiers .env
# créés par root pendant ce script inclus).
chown -R "$DEPLOY_USER":"$DEPLOY_USER" "$REPO_DIR"

cat <<EOF

──────────────────────────────────────────────────────────────────────────────
 CASA est déployé derrière Apache.

 RESTE À FAIRE :
  1. DNS : un enregistrement A « ${CASA_DOMAIN} » → IP publique de ce serveur,
     ports 80/443 ouverts depuis Internet.
  2. Certificat (si RUN_CERTBOT n'a pas été passé) :
       sudo certbot certonly --apache -d ${CASA_DOMAIN} -m ${CASA_ADMIN_EMAIL} --agree-tos --no-eff-email
       sudo systemctl reload apache2
  3. Premier administrateur (interactif) :
       docker compose --env-file .env.production \\
         -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.apache.yml \\
         exec backend php artisan casa:create-admin ${CASA_ADMIN_EMAIL}
  4. Ouvrir https://${CASA_DOMAIN} et se connecter.
──────────────────────────────────────────────────────────────────────────────
EOF
