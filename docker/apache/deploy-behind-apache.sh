#!/usr/bin/env bash
# =============================================================================
#  CASA — Déploiement « derrière un Apache existant » (Lot 11).
#
#  Automatise docs/DEPLOIEMENT.md § 15 sur un serveur qui héberge déjà d'autres
#  applications (Apache en façade sur 80/443). CASA tourne en HTTP local ; Apache
#  proxifie.
#
#  À lancer par le compte de déploiement (membre du groupe `docker`), PAS root.
#  Le script demande `sudo` uniquement pour Apache / apt / certbot.
#
#  Usage (depuis la racine du dépôt cloné, ex. /opt/casa) :
#      CASA_DOMAIN=casa.mon-domaine.ci CASA_ADMIN_EMAIL=admin@mon-domaine.ci \
#        bash docker/apache/deploy-behind-apache.sh
#
#  Variables :
#      CASA_DOMAIN        (REQUIS) sous-domaine servi par Apache
#      CASA_ADMIN_EMAIL   ServerAdmin + Let's Encrypt   (défaut: admin@$CASA_DOMAIN)
#      CASA_HTTP_PORT     port local de nginx CASA      (défaut: 8090)
#      CASA_BIND_ADDR     interface d'écoute            (défaut: 127.0.0.1)
#      CASA_SUBNET        sous-réseau Docker figé = TRUSTED_PROXIES
#                                                      (défaut: 172.31.243.0/24)
#      RUN_CERTBOT=1      enchaîne `certbot certonly --apache` à la fin
#
#  Idempotent. Ne touche jamais aux autres vhosts / conteneurs du serveur.
#  NE crée PAS le premier compte admin (acte interactif — rappelé à la fin).
# =============================================================================
set -euo pipefail

CASA_DOMAIN="${CASA_DOMAIN:?Définir CASA_DOMAIN (ex: CASA_DOMAIN=casa.mon-domaine.ci)}"
CASA_ADMIN_EMAIL="${CASA_ADMIN_EMAIL:-admin@${CASA_DOMAIN}}"
CASA_HTTP_PORT="${CASA_HTTP_PORT:-8090}"
CASA_BIND_ADDR="${CASA_BIND_ADDR:-127.0.0.1}"
CASA_SUBNET="${CASA_SUBNET:-172.31.243.0/24}"
RUN_CERTBOT="${RUN_CERTBOT:-0}"

cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

DC=(docker compose --env-file .env.production
    -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.apache.yml)

say() { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }

# --- 0. Contrôles ---------------------------------------------------------
say "Contrôles"
[ "$(id -u)" -ne 0 ] || { echo "Lancer ce script SANS sudo (compte du groupe docker)."; exit 1; }
docker compose version >/dev/null || { echo "Docker / plugin compose absent"; exit 1; }
command -v apache2ctl >/dev/null || { echo "Apache absent"; exit 1; }
if ss -tlnH "sport = :${CASA_HTTP_PORT}" 2>/dev/null | grep -q .; then
    echo "⚠️  Le port ${CASA_HTTP_PORT} est déjà pris — choisir un autre CASA_HTTP_PORT."; exit 1
fi
# DNS Docker : le BUILD (npm/composer/apk) doit résoudre les registres publics.
# Le RUNTIME n'en a pas besoin (résolveur Docker interne pour postgres/frontend).
if ! docker run --rm alpine sh -c 'nslookup registry.npmjs.org' >/dev/null 2>&1; then
    echo "⚠️  Le DNS des conteneurs Docker ne résout pas les noms publics."
    echo "    Le build va probablement échouer. Corriger avant de continuer :"
    echo "      echo '{ \"dns\": [\"8.8.8.8\", \"1.1.1.1\"] }' | sudo tee /etc/docker/daemon.json"
    echo "      sudo systemctl restart docker"
    echo "    (voir docs/DEPLOIEMENT.md § 15.1). Ctrl-C pour arrêter, ou Entrée pour tenter quand même."
    read -r _
fi

# --- 1. Fichiers .env.production ---------------------------------------
say "Configuration (.env.production)"
if [ ! -f .env.production ]; then
    cp .env.production.example .env.production
    PGPW="$(openssl rand -base64 24 | tr -d '\n' | tr '/+=' 'xyz')"
    sed -i "s|^POSTGRES_PASSWORD=.*|POSTGRES_PASSWORD=${PGPW}|" .env.production
    sed -i "s|^CASA_HTTP_PORT=.*|CASA_HTTP_PORT=${CASA_HTTP_PORT}|" .env.production
    sed -i "s|^CASA_BIND_ADDR=.*|CASA_BIND_ADDR=${CASA_BIND_ADDR}|" .env.production
    sed -i "s|^CASA_SUBNET=.*|CASA_SUBNET=${CASA_SUBNET}|" .env.production
    echo "  → .env.production créé (mot de passe Postgres généré, sous-réseau ${CASA_SUBNET})"
else
    PGPW="$(grep -E '^POSTGRES_PASSWORD=' .env.production | cut -d= -f2-)"
    echo "  → .env.production existant conservé"
fi

if [ ! -f backend/.env.production ]; then
    cp backend/.env.production.example backend/.env.production
    APPKEY="$("${DC[@]}" run --rm --no-deps --entrypoint php backend artisan key:generate --show 2>/dev/null | tail -1)"
    sed -i "s|^APP_KEY=.*|APP_KEY=${APPKEY}|"                                        backend/.env.production
    sed -i "s|^APP_URL=.*|APP_URL=https://${CASA_DOMAIN}|"                           backend/.env.production
    sed -i "s|^SESSION_DOMAIN=.*|SESSION_DOMAIN=${CASA_DOMAIN}|"                     backend/.env.production
    sed -i "s|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=${CASA_DOMAIN}|" backend/.env.production
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${PGPW}|"                                  backend/.env.production
    # Durcissement : TRUSTED_PROXIES = sous-réseau Docker de CASA, JAMAIS « * »
    # (sinon usurpation d'IP via X-Forwarded-For → rate-limiting keyé IP
    # contourné + logs pollués). Doit être IDENTIQUE à CASA_SUBNET.
    sed -i "s|^TRUSTED_PROXIES=.*|TRUSTED_PROXIES=${CASA_SUBNET}|"                   backend/.env.production
    echo "  → backend/.env.production créé (APP_KEY généré, domaine ${CASA_DOMAIN}, TRUSTED_PROXIES=${CASA_SUBNET})"
else
    echo "  → backend/.env.production existant conservé"
    case "$(grep -E '^TRUSTED_PROXIES=' backend/.env.production | cut -d= -f2-)" in
        '*'|'') echo "  ⚠️  TRUSTED_PROXIES vaut « * » (ou vide) — DANGEREUX ici."
                echo "      Mettre : TRUSTED_PROXIES=${CASA_SUBNET}  puis relancer." ;;
    esac
fi
"${DC[@]}" config -q && echo "  → compose OK"

# --- 2. Démarrage de CASA (HTTP local) --------------------------------
say "Build + démarrage de CASA (quelques minutes au premier lancement)"
"${DC[@]}" up -d --build
echo -n "  Attente des services healthy "
for _ in $(seq 1 72); do
    n=$("${DC[@]}" ps --format '{{.Health}}' | grep -c healthy || true)
    [ "$n" -ge 4 ] && { echo " OK ($n/4)"; break; }
    echo -n "."; sleep 5
done
"${DC[@]}" ps

# Le sous-réseau réel DOIT correspondre à TRUSTED_PROXIES (sinon Laravel ne
# fait confiance à aucun proxy → schéma HTTPS non vu, IP client fausse).
REAL_SUBNET="$(docker network inspect casa_casa -f '{{(index .IPAM.Config 0).Subnet}}' 2>/dev/null || echo '?')"
if [ "$REAL_SUBNET" = "$CASA_SUBNET" ]; then
    echo "  ✓ réseau casa_casa = ${CASA_SUBNET} (= TRUSTED_PROXIES)"
else
    echo "  ⚠️  réseau casa_casa = ${REAL_SUBNET} ≠ CASA_SUBNET (${CASA_SUBNET})."
    echo "      Le sous-réseau ${CASA_SUBNET} est peut-être déjà pris. Choisir une"
    echo "      autre valeur : relancer avec CASA_SUBNET=10.201.0.0/24 (par ex.),"
    echo "      après « ${DC[*]} down » pour recréer le réseau."
    exit 1
fi

# --- 3. Base de données ---------------------------------------------
say "Migrations + référentiel (sans comptes démo)"
"${DC[@]}" exec -T backend php artisan migrate --force
"${DC[@]}" exec -T backend php artisan casa:seed-referentiel

# --- 4. VirtualHost Apache ----------------------------------------
say "Configuration Apache (sudo)"
sudo a2enmod proxy proxy_http headers rewrite ssl >/dev/null
sed -e "s|casa\.exemple\.ci|${CASA_DOMAIN}|g" \
    -e "s|admin@exemple\.ci|${CASA_ADMIN_EMAIL}|g" \
    -e "s|http://127\.0\.0\.1:8090|http://${CASA_BIND_ADDR}:${CASA_HTTP_PORT}|g" \
    docker/apache/casa.vhost.conf | sudo tee /etc/apache2/sites-available/casa.conf >/dev/null
sudo a2ensite casa >/dev/null
sudo apache2ctl configtest
sudo systemctl reload apache2
echo "  → vhost casa.conf installé et activé (domaine ${CASA_DOMAIN})"

# --- 5. Vérifications -------------------------------------------
say "Vérifications"
curl -fsS "http://${CASA_BIND_ADDR}:${CASA_HTTP_PORT}/up" >/dev/null \
    && echo "  ✓ CASA répond en local (${CASA_BIND_ADDR}:${CASA_HTTP_PORT}/up)"
echo "  ✓ via Apache (Host: ${CASA_DOMAIN}) : HTTP $(curl -s -o /dev/null -w '%{http_code}' -H "Host: ${CASA_DOMAIN}" http://127.0.0.1/up)"

# --- 6. Certificat (optionnel) --------------------------------
if [ "$RUN_CERTBOT" = "1" ]; then
    say "Certificat Let's Encrypt (sudo)"
    command -v certbot >/dev/null || sudo apt-get install -y certbot
    sudo certbot certonly --apache -d "$CASA_DOMAIN" -m "$CASA_ADMIN_EMAIL" --agree-tos --no-eff-email
    echo 'systemctl reload apache2' | sudo tee /etc/letsencrypt/renewal-hooks/deploy/reload-apache.sh >/dev/null
    sudo chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-apache.sh
    sudo systemctl reload apache2
    echo "  → HTTPS actif, :80 redirige vers :443"
fi

cat <<EOF

──────────────────────────────────────────────────────────────────────────────
 CASA est déployé derrière Apache.

 RESTE À FAIRE :
  1. DNS : un enregistrement A « ${CASA_DOMAIN} » → IP publique de ce serveur ;
     ports 80/443 ouverts depuis Internet (pour la validation Let's Encrypt).
  2. Certificat (si RUN_CERTBOT n'a pas été passé) :
       sudo certbot certonly --apache -d ${CASA_DOMAIN} -m ${CASA_ADMIN_EMAIL} --agree-tos --no-eff-email
       sudo systemctl reload apache2
  3. E-mail (docs/DEPLOIEMENT.md § 9) — par défaut « log » (rien n'est envoyé) :
       éditer backend/.env.production : MAIL_MAILER=smtp + MAIL_HOST/USERNAME/PASSWORD…
       ${DC[*]} up -d --force-recreate backend worker
       ${DC[*]} exec backend php artisan casa:test-email vous@${CASA_DOMAIN}
  4. Comptes de l'équipe (interactif — docs/DEPLOIEMENT.md § 10) :
       ${DC[*]} exec backend php artisan casa:create-admin ${CASA_ADMIN_EMAIL}
       ${DC[*]} exec backend php artisan casa:create-membre <email>   # un par évaluateur du jury
  5. Ouvrir https://${CASA_DOMAIN} et se connecter.
──────────────────────────────────────────────────────────────────────────────
EOF
