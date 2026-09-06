#!/usr/bin/env bash
# =============================================================================
#  CASA — Hook de déploiement certbot (Lot 9b).
#
#  À passer à certbot en `--deploy-hook` : copie le certificat renouvelé de
#  Let's Encrypt (fichiers PLATS, sans les symlinks de live/) vers ${TLS_DIR}
#  monté dans le conteneur nginx, puis recharge nginx sans coupure.
#
#  Exemple d'appel (hôte Ubuntu) :
#    sudo certbot certonly --webroot -w /srv/casa/docker/nginx/acme-webroot \
#         -d casa.example.org \
#         --deploy-hook "DOMAIN=casa.example.org TLS_DIR=/srv/casa/docker/nginx/tls \
#                        COMPOSE_DIR=/srv/casa /srv/casa/docker/nginx/renew-hook.sh"
#
#  Le renouvellement périodique est assuré par le timer systemd de certbot
#  (`systemctl list-timers | grep certbot`), installé par `apt install certbot`.
#  Rien à conteneuriser. (Variante : un service `certbot` dans compose — non
#  retenue, cf. ADR-27.)
# =============================================================================
set -euo pipefail

DOMAIN="${DOMAIN:?DOMAIN requis}"
TLS_DIR="${TLS_DIR:?TLS_DIR requis (montage host de /etc/nginx/tls)}"
COMPOSE_DIR="${COMPOSE_DIR:-.}"
LE_DIR="${LE_DIR:-/etc/letsencrypt/live/$DOMAIN}"

install -m 644 "$LE_DIR/fullchain.pem" "$TLS_DIR/fullchain.pem"
install -m 600 "$LE_DIR/privkey.pem"  "$TLS_DIR/privkey.pem"

cd "$COMPOSE_DIR"
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T nginx nginx -s reload
echo "→ Certificat $DOMAIN installé dans $TLS_DIR et nginx rechargé."
