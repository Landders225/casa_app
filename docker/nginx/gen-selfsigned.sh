#!/usr/bin/env bash
# (Windows/Git-Bash : préfixer MSYS_NO_PATHCONV=1 pour le test local ; inutile sur Ubuntu.)
# =============================================================================
#  CASA — Certificat TLS auto-signé de BOOTSTRAP (Lot 9b).
#
#  nginx (prod) exige un certificat pour démarrer. Or Let's Encrypt (certbot
#  --webroot) a besoin de nginx en marche pour servir le challenge ACME :
#  œuf/poule. Ce script pose un cert jetable dans ${TLS_DIR} pour amorcer.
#
#  Usage :
#    TLS_DIR=./docker/nginx/tls DOMAIN=casa.example.org ./docker/nginx/gen-selfsigned.sh
#
#  Puis, une fois nginx en marche, obtenir le vrai certificat (voir README,
#  section « Configuration de production ») et le copier dans ${TLS_DIR}
#  (fullchain.pem + privkey.pem), enfin `docker compose ... exec nginx nginx -s reload`.
#
#  Ce répertoire est gitignoré : aucun certificat / clé n'est versionné.
# =============================================================================
set -euo pipefail

TLS_DIR="${TLS_DIR:-./docker/nginx/tls}"
DOMAIN="${DOMAIN:-casa.localhost}"
DAYS="${DAYS:-365}"

mkdir -p "$TLS_DIR"

if [ -s "$TLS_DIR/fullchain.pem" ] && [ -s "$TLS_DIR/privkey.pem" ] && [ "${FORCE:-0}" != "1" ]; then
  echo "→ $TLS_DIR/{fullchain,privkey}.pem existent déjà (FORCE=1 pour régénérer). Rien à faire."
  exit 0
fi

echo "→ Génération d'un certificat auto-signé pour « $DOMAIN » ($DAYS j) dans $TLS_DIR"
openssl req -x509 -newkey rsa:2048 -nodes -days "$DAYS" \
  -keyout "$TLS_DIR/privkey.pem" \
  -out "$TLS_DIR/fullchain.pem" \
  -subj "/CN=$DOMAIN/O=CASA (bootstrap self-signed)" \
  -addext "subjectAltName=DNS:$DOMAIN,DNS:localhost,IP:127.0.0.1"

chmod 600 "$TLS_DIR/privkey.pem"
chmod 644 "$TLS_DIR/fullchain.pem"
echo "→ OK. ⚠️  Certificat de BOOTSTRAP uniquement — à remplacer par un vrai cert avant mise en service."
