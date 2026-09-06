#!/bin/sh
# =============================================================================
#  CASA — entrypoint du conteneur backend en PRODUCTION (Lot 9c, ADR-28).
#
#  Branché par docker-compose.prod.yml (`entrypoint:`). Le Dockerfile de base
#  reste inchangé (dev = `CMD ["php-fpm"]` sans cache).
#
#  Reconstruit les caches Laravel À CHAQUE DÉMARRAGE / --force-recreate → ils
#  sont TOUJOURS cohérents avec l'environnement courant. C'est ce qui résout le
#  piège « config:cache fige l'env » : après un changement de
#  backend/.env.production, un `up -d --force-recreate backend` suffit — les
#  caches sont refaits ici avec les nouvelles valeurs.
#
#  Les MIGRATIONS ne sont PAS ici (risque : réplicas, migration bloquante au
#  démarrage) — c'est une étape manuelle documentée dans docs/DEPLOIEMENT.md :
#      docker compose ... exec backend php artisan migrate --force
# =============================================================================
set -e

echo "[entrypoint] Reconstruction des caches Laravel…"
php artisan config:clear  --no-interaction
php artisan config:cache  --no-interaction
php artisan route:cache   --no-interaction
php artisan event:cache   --no-interaction
php artisan view:cache    --no-interaction
echo "[entrypoint] Caches prêts. Démarrage."

# Exécute la commande du conteneur. docker-compose.prod.yml redéclare
# `command: ["php-fpm"]` (Compose vide le CMD de l'image quand `entrypoint`
# est surchargé) ; ce défaut couvre le cas où "$@" arriverait vide.
if [ "$#" -eq 0 ]; then set -- php-fpm; fi
exec "$@"
