# Déploiement de CASA en production

> **But.** Partir d'un serveur **Ubuntu vierge** et arriver à une instance CASA
> qui tourne en HTTPS, avec un premier compte administrateur et des sauvegardes.
> Chaque commande est donnée **en entier** — aucune étape « configurez X » sans
> le _comment_.
>
> Ce document a été **suivi en conditions prod-like** au Lot 9c (domaine
> `casa.localhost`, certificat auto-signé) : voir le récapitulatif en fin de
> fichier.

---

## 0. Vue d'ensemble

**Deux modes de déploiement** — choisir selon le serveur :

| Mode | Quand | Sections |
|---|---|---|
| **A — Autonome** (défaut) | serveur dédié à CASA, CASA prend 80/443 et termine le TLS lui-même | § 1 → § 12 |
| **T — Test local HTTP** | valider le fonctionnel sur le serveur **avant** de monter Apache/HTTPS ; accès navigateur direct `http://<IP>:8090`, pas de domaine | **§ 13** |
| **B — Derrière un Apache existant** | le serveur héberge déjà d'autres apps, Apache est en façade sur 80/443 ; CASA tourne en HTTP local sur un port dédié, Apache proxifie | **§ 14** (+ § 4, § 8, § 9 communes) |

Le mode **T** est une étape intermédiaire jetable : on l'utilise pour vérifier
que CASA tourne, puis on bascule sur le mode **B** (le vrai). Le reste de ce
tableau et les sections 1–12 décrivent le **mode A**.

| Élément | Choix | Où c'est défini |
|---|---|---|
| Orchestration | Docker Compose, **override de prod** (le fichier dev n'est jamais modifié) | `docker-compose.prod.yml` (ADR-27) |
| Point d'entrée | **nginx** unique, termine le TLS (`:80` → 301 `:443`) | `docker/nginx/casa.prod.conf` |
| Certificat | **certbot sur l'hôte** (`apt`), `--webroot`, renouvellement par timer systemd | `docker/nginx/renew-hook.sh` |
| Caches Laravel | reconstruits **à chaque démarrage du conteneur** (`config/route/event/view:cache`) | `docker/backend/entrypoint.sh` (ADR-28) |
| Migrations | **manuelles** (jamais au démarrage) | § 7 |
| Référentiel | `php artisan casa:seed-referentiel` — **sans** les comptes démo | § 8 |
| Premier admin | `php artisan casa:create-admin <email>` | § 9 |
| Secrets | 2 fichiers `.env.production` **gitignorés**, jamais dans l'image | § 4 |

Les 4 services : `postgres` (16), `backend` (Laravel/PHP-FPM), `frontend` (build
React servi par un nginx interne), `nginx` (reverse-proxy + TLS).

### Raccourci de commande

Toutes les commandes compose de prod prennent les mêmes drapeaux. Poser un alias
une fois par session shell (ou dans `~/.bashrc` du compte de déploiement) :

```bash
cd /srv/casa                       # le répertoire du clone (cf. § 3)
alias dcp='docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml'
```

Dans la suite, `dcp` = cette commande complète. Sans l'alias, remplacer `dcp`
par `docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml`.

---

## 1. Prérequis serveur

- **Ubuntu Server 22.04 LTS ou 24.04 LTS**, à jour :
  ```bash
  sudo apt update && sudo apt upgrade -y
  ```
- **Dimensionnement minimum** : 2 vCPU, 4 Go RAM, 20 Go disque. Postgres + 3
  conteneurs + build frontend tiennent dans 4 Go ; prévoir plus de disque si le
  volume des pièces justificatives grossit.
- **Un nom de domaine** pointant sur l'IP publique du serveur : un enregistrement
  **A** (IPv4) et, si le serveur a une IPv6, un **AAAA**. Vérifier :
  ```bash
  dig +short casa.example.org        # doit renvoyer l'IP du serveur
  ```
- **Ports 80 et 443 ouverts** depuis Internet (certbot `--webroot` a besoin du 80).
  Avec UFW :
  ```bash
  sudo ufw allow OpenSSH
  sudo ufw allow 80/tcp
  sudo ufw allow 443/tcp
  sudo ufw enable
  ```
- **Docker Engine + plugin Compose** (v2.24+ requis pour le tag `!override`) :
  ```bash
  curl -fsSL https://get.docker.com | sudo sh
  sudo usermod -aG docker "$USER"        # puis se reconnecter (nouvelle session)
  docker compose version                 # doit afficher v2.24 ou plus
  ```
- **Git** :
  ```bash
  sudo apt install -y git
  ```

---

## 2. Compte et emplacement

Travailler avec un utilisateur **non-root** membre du groupe `docker` (créé au
§ 1). Emplacement conventionnel dans ce guide : `/srv/casa`.

```bash
sudo mkdir -p /srv/casa
sudo chown "$USER":"$USER" /srv/casa
```

---

## 3. Récupération du code

```bash
git clone <URL_DU_DEPOT> /srv/casa
cd /srv/casa

# Déployer une version FIGÉE, pas la pointe d'une branche :
git tag                         # liste les versions disponibles
git checkout v1.0.0             # remplacer par le tag voulu
```

> Si le dépôt n'a pas encore de tag, en poser un sur le commit à déployer
> (`git tag -a v1.0.0 -m "Première mise en production" && git push origin v1.0.0`)
> avant de continuer. Déployer un tag garantit que `git checkout` sur le serveur
> redonne exactement le même arbre.

Poser l'alias `dcp` maintenant (cf. § 0).

---

## 4. Configuration (`.env.production`)

Deux fichiers, **tous deux gitignorés**, à créer depuis leurs modèles :

```bash
cp .env.production.example .env.production
cp backend/.env.production.example backend/.env.production
```

### 4.1 `.env.production` (racine — variables lues par Compose)

```bash
nano .env.production
```

| Variable | Valeur à mettre | Comment l'obtenir |
|---|---|---|
| `POSTGRES_PASSWORD` | un mot de passe fort | `openssl rand -base64 24` |
| `POSTGRES_DB` | `casa` | — (laisser) |
| `POSTGRES_USER` | `casa` | — (laisser) |
| `TLS_DIR` | `./docker/nginx/tls` | — (laisser ; alimenté au § 6) |

### 4.2 `backend/.env.production` (configuration Laravel)

```bash
nano backend/.env.production
```

| Variable | Valeur | Comment l'obtenir |
|---|---|---|
| `APP_KEY` | `base64:…` | `dcp run --rm --no-deps --entrypoint php backend artisan key:generate --show` puis coller la sortie |
| `APP_URL` | `https://casa.example.org` | votre domaine, **avec `https://`, sans `/` final** |
| `SESSION_DOMAIN` | `casa.example.org` | le **host exact**, sans port ni schéma. Apex + `www` : mettre `.example.org` (point initial) |
| `SANCTUM_STATEFUL_DOMAINS` | `casa.example.org` | le/les host(s) ; ajouter le port **seulement** s'il est non-standard |
| `DB_PASSWORD` | **la même valeur** que `POSTGRES_PASSWORD` ci-dessus | copier-coller |
| `TRUSTED_PROXIES` | `*` pour démarrer ; à resserrer (§ 10.5) | voir § 10.5 |

Les autres variables (`APP_ENV=production`, `APP_DEBUG=false`,
`SESSION_SECURE_COOKIE=true`, `LOG_CHANNEL=stderr`, …) sont **déjà fixées** dans
le modèle — ne pas y toucher.

> **Contrôle rapide** avant de continuer :
> ```bash
> dcp config -q && echo "compose OK"
> grep -E '^(APP_KEY|APP_URL|SESSION_DOMAIN|DB_PASSWORD)=' backend/.env.production
> grep -E '^POSTGRES_PASSWORD=' .env.production
> ```
> `APP_KEY` doit être non vide et `DB_PASSWORD` == `POSTGRES_PASSWORD`.

---

## 5. Pare-feu applicatif (rappel)

Rien à faire de plus qu'au § 1 : seuls 22/80/443 sont exposés. Les ports de
Postgres (5432) et PHP-FPM (9000) **ne sont pas publiés** par l'override de prod —
ils ne sont joignables que depuis le réseau Docker interne.

---

## 6. Certificat TLS

nginx **refuse de démarrer sans certificat**. Or Let's Encrypt (`certbot
--webroot`) a besoin de nginx **en marche** pour répondre au challenge ACME :
œuf / poule. On amorce donc avec un certificat auto-signé, puis on le remplace.

**Ordre réel** : faire **§ 6.1** maintenant, puis **§ 7** (démarrage), puis
**revenir à § 6.2** pour le vrai certificat.

### 6.1 Certificat auto-signé de bootstrap

```bash
TLS_DIR=./docker/nginx/tls DOMAIN=casa.example.org ./docker/nginx/gen-selfsigned.sh
```

Crée `docker/nginx/tls/fullchain.pem` + `privkey.pem` (répertoire gitignoré).
_(Sur Windows/Git-Bash uniquement : préfixer `MSYS_NO_PATHCONV=1`. Sur Ubuntu, non.)_

### 6.2 Démarrer la stack (§ 7), PUIS obtenir le vrai certificat

certbot sur l'hôte, méthode `--webroot` (nginx sert déjà
`/.well-known/acme-challenge/` depuis `docker/nginx/acme-webroot`) :

```bash
sudo apt install -y certbot

sudo certbot certonly --webroot \
  -w /srv/casa/docker/nginx/acme-webroot \
  -d casa.example.org \
  --agree-tos -m admin@casa.example.org --no-eff-email \
  --deploy-hook "DOMAIN=casa.example.org TLS_DIR=/srv/casa/docker/nginx/tls COMPOSE_DIR=/srv/casa /srv/casa/docker/nginx/renew-hook.sh"
```

Le `--deploy-hook` copie le certificat obtenu (fichiers **plats**, pas les
symlinks de `live/`) dans `TLS_DIR` et recharge nginx. Il est **aussi** rejoué à
chaque renouvellement automatique.

Le hook n'est déclenché qu'à l'**émission**. Pour l'appliquer tout de suite après
cette première obtention :

```bash
sudo DOMAIN=casa.example.org TLS_DIR=/srv/casa/docker/nginx/tls COMPOSE_DIR=/srv/casa \
  /srv/casa/docker/nginx/renew-hook.sh
```

### 6.3 Vérifier

```bash
sudo certbot certificates                 # doit lister casa.example.org, VALID
sudo certbot renew --dry-run              # simule un renouvellement
systemctl list-timers | grep certbot      # le timer de renouvellement est actif
curl -sI https://casa.example.org/up | grep -i "^HTTP"   # 200, sans -k
```

> **Apex + www** : ajouter `-d www.casa.example.org` à la commande certbot et
> mettre `SESSION_DOMAIN=.casa.example.org` (point initial) dans
> `backend/.env.production`, puis recréer le backend (§ 11.6).

---

## 7. Démarrage de la stack

```bash
dcp up -d --build
```

- `--build` construit les images backend et frontend depuis le code du tag.
- L'**entrypoint** du backend reconstruit les caches Laravel au démarrage
  (visible dans les logs : `[entrypoint] Reconstruction des caches Laravel…`).

Attendre que les 4 services soient `healthy` :

```bash
watch -n 3 'dcp ps'
# Ctrl-C quand les 4 lignes affichent (healthy)
```

En cas de souci, voir les logs : `dcp logs -f backend` (§ 11.8 et § 11.9).

---

## 8. Base de données

Les migrations sont **manuelles et non destructives**.

```bash
# Applique les migrations manquantes. JAMAIS migrate:fresh en prod (efface tout).
dcp exec backend php artisan migrate --force

# Amorce le référentiel : types de documents, filières, campagne, grille de barème.
# N'installe PAS les comptes de démonstration (mots de passe publics = faille).
dcp exec backend php artisan casa:seed-referentiel
```

`casa:seed-referentiel` est **prudent** : s'il détecte que le référentiel est
déjà là (table `grille` ou `filiere` non vide), il ne fait rien (`--force` pour
outrepasser ; les seeders sont idempotents).

Vérifier :

```bash
dcp exec backend php artisan tinker --execute \
  "echo DB::table('filiere')->count().' filières, '.DB::table('grille')->count().' grille, '.DB::table('type_document')->count().' types de doc, '.DB::table('campagne')->count().' campagne, '.DB::table('utilisateur')->count().' comptes';"
# attendu : 5 filières, 1 grille, 6 types de doc, 1 campagne, 0 compte
```

---

## 9. Premier compte administrateur

Aucun compte n'existe encore. Cette commande est le **seul** moyen prévu d'en
créer un en prod (interactive — mot de passe jamais en clair dans l'historique
shell) :

```bash
dcp exec backend php artisan casa:create-admin coordination@casa.example.org
```

Elle demande : mot de passe (min. 10 caractères, majuscule + minuscule +
chiffre) et confirmation, puis prénom / nom / poste. Elle valide comme à
l'inscription, refuse un e-mail déjà pris, écrit dans une transaction et **trace
une ligne d'audit**.

Se connecter ensuite sur `https://casa.example.org/connexion`.

> Créer les comptes **évaluateurs** se fait ensuite depuis l'interface
> d'administration (espace admin → équipe), ou par la même logique si une
> commande dédiée est ajoutée plus tard. À ce jour, `casa:create-admin` ne crée
> que des administrateurs.

---

## 10. Vérifications post-déploiement

Checklist à cocher. Remplacer le domaine.

```bash
# 1. La SPA se charge en HTTPS (200, HTML)
curl -sI https://casa.example.org/ | grep -i "^HTTP"          # 200

# 2. Certificat réel (pas le self-signed de bootstrap)
echo | openssl s_client -connect casa.example.org:443 -servername casa.example.org 2>/dev/null \
  | openssl x509 -noout -issuer -dates                        # issuer = Let's Encrypt

# 3. Les 6 en-têtes de sécurité, sur / ET sur /api
for p in / /api/filieres; do echo "== $p =="; curl -sI "https://casa.example.org$p" \
  | grep -iE "strict-transport|x-content-type|x-frame|referrer-policy|permissions-policy|content-security-policy"; done

# 4. Redirection HTTP -> HTTPS
curl -sI http://casa.example.org/ | grep -iE "^HTTP|^location"   # 301 -> https://

# 5. Sonde backend
curl -s https://casa.example.org/up | head -c 40; echo           # page "up" Laravel

# 6. APP_DEBUG=false : une route inexistante ne fuit ni trace ni chemin
curl -s https://casa.example.org/api/route-qui-nexiste-pas | head -c 200
#   attendu : {"message":"..."} générique, aucun chemin /var/www, aucun SQL

# 7. Les pièces restent hors du web
curl -sI https://casa.example.org/storage/ | grep -i "^HTTP"     # 404
curl -sI https://casa.example.org/.env     | grep -i "^HTTP"     # 404

# 8. Caches Laravel actifs
dcp exec backend php artisan about | grep -iE "config|route|event|cache"
#   Config .......... CACHED   Routes .......... CACHED   Events .......... CACHED

# 9. Login admin réel (remplacer le mot de passe)
#    L'en-tête Origin est INDISPENSABLE en curl : Sanctum n'attache la session
#    qu'aux requêtes « frontend » (Origin/Referer ∈ SANCTUM_STATEFUL_DOMAINS).
#    Un navigateur l'envoie toujours ; sans lui, curl reçoit un 500
#    « Session store not set » — ce n'est pas un bug, juste une requête hors SPA.
curl -sc /tmp/j https://casa.example.org/sanctum/csrf-cookie -o /dev/null
XSRF=$(awk '/XSRF-TOKEN/{print $7}' /tmp/j | perl -pe 's/%([0-9A-Fa-f]{2})/chr hex $1/ge')
curl -s -b /tmp/j -c /tmp/j -X POST https://casa.example.org/api/login \
  -H "Content-Type: application/json" -H "Origin: https://casa.example.org" \
  -H "X-XSRF-TOKEN: $XSRF" \
  -d '{"email":"coordination@casa.example.org","password":"VOTRE_MOT_DE_PASSE"}' | head -c 200
#   attendu : l'objet utilisateur (role=administrateur), jamais mot_de_passe_hash
```

Enfin, **dans un navigateur** : ouvrir `https://casa.example.org`, se connecter,
vérifier qu'aucune erreur CSP n'apparaît dans la console (onglet Console des
devtools).

---

## 11. Maintenance

### 11.1 Sauvegarde de la base (quotidienne)

```bash
sudo mkdir -p /srv/backups && sudo chown "$USER":"$USER" /srv/backups
```

Script `/srv/casa/scripts-hote/backup-db.sh` (à créer sur l'hôte, hors dépôt) :

```bash
#!/usr/bin/env bash
set -euo pipefail
cd /srv/casa
STAMP=$(date +%Y%m%d-%H%M%S)
docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml \
  exec -T postgres pg_dump -U casa -d casa | gzip > "/srv/backups/casa-$STAMP.sql.gz"
# Rotation : garder 14 jours
find /srv/backups -name 'casa-*.sql.gz' -mtime +14 -delete
```

```bash
chmod +x /srv/casa/scripts-hote/backup-db.sh
crontab -e
# ajouter :
15 2 * * * /srv/casa/scripts-hote/backup-db.sh >> /srv/backups/backup.log 2>&1
```

### 11.2 Sauvegarde des pièces justificatives

Elles vivent dans le volume Docker `casa_documents_data` (monté sur
`storage/app/private`). Sauvegarde :

```bash
docker run --rm -v casa_documents_data:/data -v /srv/backups:/out alpine \
  tar czf /out/casa-documents-$(date +%Y%m%d).tar.gz -C /data .
```

### 11.3 Restauration

```bash
# Base :
gunzip -c /srv/backups/casa-20260101-021500.sql.gz | \
  dcp exec -T postgres psql -U casa -d casa

# Pièces :
docker run --rm -v casa_documents_data:/data -v /srv/backups:/in alpine \
  sh -c 'cd /data && tar xzf /in/casa-documents-20260101.tar.gz'
```

### 11.4 Mise à jour de l'application

```bash
cd /srv/casa
/srv/casa/scripts-hote/backup-db.sh          # sauvegarde d'abord
git fetch --tags
git checkout v1.1.0                           # le nouveau tag

dcp up -d --build                             # reconstruit + recrée
#   -> l'entrypoint refait les caches avec le code neuf
dcp exec backend php artisan migrate --force  # migrations éventuelles

dcp ps                                        # 4x healthy
# rejouer la checklist § 10 (au moins 1, 5, 8, 9)
```

**Rollback** si la nouvelle version pose problème :

```bash
git checkout v1.0.0
dcp up -d --build
# si une migration doit être défaite : dcp exec backend php artisan migrate:rollback --force
# sinon, restaurer la base depuis la sauvegarde d'avant mise à jour (§ 11.3)
```

### 11.5 Resserrer `TRUSTED_PROXIES` (recommandé)

`*` fonctionne mais laisse une marge. Une fois la stack up :

```bash
docker network inspect casa_casa -f '{{(index .IPAM.Config 0).Subnet}}'
#   ex. : 172.20.0.0/16
```

Mettre cette valeur dans `backend/.env.production` (`TRUSTED_PROXIES=172.20.0.0/16`),
puis recréer le backend :

```bash
dcp up -d --force-recreate backend
```

(L'entrypoint refait `config:cache` avec la nouvelle valeur — c'est ce mécanisme
qui résout le piège « `config:cache` fige l'env », ADR-28.)

### 11.6 Après TOUT changement d'un `.env.production`

```bash
dcp up -d --force-recreate backend     # les caches sont refaits au redémarrage
```

Ne **jamais** éditer un `.env` et attendre que ça prenne : sans recreate, les
caches figés gardent l'ancienne valeur.

### 11.7 Renouvellement du certificat

Automatique (timer systemd de certbot, installé avec le paquet). Le
`--deploy-hook` recopie le certificat renouvelé et recharge nginx. Contrôle
ponctuel :

```bash
sudo certbot renew --dry-run
systemctl list-timers | grep certbot
```

### 11.8 Logs

```bash
dcp logs -f backend            # applicatif Laravel (LOG_CHANNEL=stderr)
dcp logs -f nginx              # accès / erreurs proxy + TLS
dcp logs --since 1h            # tout, dernière heure
```

Le journal d'audit métier (append-only, ADR-12) est **dans la base**, consultable
depuis l'espace admin — c'est une donnée, pas un log serveur.

### 11.9 « Si X casse »

| Symptôme | Cause probable | Correctif |
|---|---|---|
| `nginx` ne démarre pas, `cannot load certificate` | `docker/nginx/tls/` vide | rejouer § 6.1 (`gen-selfsigned.sh`), puis `dcp up -d nginx` |
| `backend` `unhealthy`, logs `SQLSTATE… password authentication failed` | `DB_PASSWORD` ≠ `POSTGRES_PASSWORD` | aligner les deux, `dcp up -d --force-recreate backend` |
| `backend` logs `No application encryption key` | `APP_KEY` vide dans `backend/.env.production` | générer (§ 4.2), `dcp up -d --force-recreate backend` |
| Login → boucle, ou `419` systématique **dans le navigateur** | `SESSION_DOMAIN` ou `SANCTUM_STATEFUL_DOMAINS` ne matchent pas le host servi | corriger au host exact, `--force-recreate backend` |
| `500` sur toutes les pages après un changement d'env | caches incohérents | `dcp exec backend php artisan config:clear` puis `dcp up -d --force-recreate backend` |
| Erreur CSP dans la console navigateur après ajout d'une lib front | la lib charge une ressource externe (CDN, police) | inliner l'asset, ou ajuster la CSP dans `casa.prod.conf` (cf. ADR-27) et `dcp exec nginx nginx -s reload` |
| Disque plein | images / volumes orphelins | `docker system df` puis `docker system prune` (⚠️ pas `-a --volumes` sans réfléchir) |
| Besoin de créer / modifier une **campagne** (dates, quotas) | pas d'UI de création (point ouvert **D-6a-2**, cf. `docs/POINTS-OUVERTS.md`) | `dcp exec backend php artisan tinker` puis `INSERT`/`UPDATE` sur `campagne` ; garde-fou « une seule ouverte » appliqué par l'app |

---

## 12. Arrêt

```bash
dcp stop                # arrête les conteneurs, garde les données
dcp down                # supprime les conteneurs + le réseau, garde les volumes
dcp down -v             # ⚠️ SUPPRIME AUSSI LES VOLUMES = perte de la base et des pièces
```

---

## 13. Test local en HTTP direct (étape intermédiaire)

> **But.** Avant de monter Apache + Let's Encrypt (§ 14), valider que CASA
> **tourne** et que le **login fonctionne**, en tapant simplement
> `http://<IP-du-serveur>:8090` dans un navigateur. Pas de domaine, pas de
> HTTPS, pas d'Apache : le navigateur parle **directement** au nginx de CASA.
>
> ```
> Navigateur ──HTTP──▶ nginx CASA  0.0.0.0:8090  ──▶ backend / frontend
> ```
>
> ⚠️ **Mode jetable.** Les cookies sont **non-`Secure`** (obligatoire pour un
> login en HTTP) : à **ne pas exposer sur Internet**. Le passage en production
> réelle (§ 14) **exige** de remettre `SESSION_SECURE_COOKIE=true` + le vrai
> domaine.

### 13.1 Prérequis

- § 1 (Docker + Compose ≥ 2.24, Git) et le code cloné (§ 3), p. ex. dans `/opt/casa`.
- Le **port `8090` libre** : `sudo ss -tlnp | grep ':8090' || echo libre`.
- **DNS des conteneurs Docker fonctionnel** — le *build* (`npm` / `composer` /
  `apk`) télécharge des paquets ; le *runtime* de CASA, lui, n'a besoin d'aucun
  DNS externe. Tester :
  ```bash
  docker run --rm alpine nslookup registry.npmjs.org
  ```
  Si ça échoue :
  ```bash
  echo '{ "dns": ["8.8.8.8", "1.1.1.1"] }' | sudo tee /etc/docker/daemon.json
  sudo systemctl restart docker
  ```
  *(Remplacer par les résolveurs internes de l'organisation si `8.8.8.8` est bloqué.)*

### 13.2 Raccourci — le script

```bash
cd /opt/casa
CASA_SERVER_IP=172.30.4.200 bash docker/deploy-test-local.sh
```

Il crée les `.env.test-local`, build + up, migrate + `casa:seed-referentiel`,
et vérifie (`/up`, SPA, **anti-usurpation d'IP**). Détail manuel ci-dessous.

### 13.3 Configuration

Deux fichiers **gitignorés**, depuis leurs modèles :

```bash
cp .env.test-local.example .env.test-local
cp backend/.env.test-local.example backend/.env.test-local
```

**`.env.test-local`** (racine) : `POSTGRES_PASSWORD` (fort), `CASA_HTTP_PORT=8090`,
`CASA_BIND_ADDR=0.0.0.0`, `CASA_SUBNET=172.31.243.0/24`.

> **`CASA_BIND_ADDR=0.0.0.0`** = nginx écoute sur **toutes les interfaces** →
> `http://172.30.4.200:8090` est joignable depuis le réseau. Avec `127.0.0.1`,
> le port ne serait atteignable **que depuis le serveur lui-même** (le loopback
> n'est pas routé).

**`backend/.env.test-local`** — remplacer `SERVEUR_IP` par l'IP réelle
(`172.30.4.200`) partout :

| Variable | Valeur (test) | Prod réelle (§ 14) |
|---|---|---|
| `APP_URL` | `http://172.30.4.200:8090` | `https://casa.mon-domaine.ci` |
| `SESSION_DOMAIN` | `172.30.4.200` | `casa.mon-domaine.ci` |
| `SANCTUM_STATEFUL_DOMAINS` | `172.30.4.200:8090` *(avec le port !)* | `casa.mon-domaine.ci` |
| `SESSION_SECURE_COOKIE` | **`false`** | **`true`** |
| `APP_KEY` | généré (ci-dessous) | — |
| `DB_PASSWORD` | = `POSTGRES_PASSWORD` | — |
| `TRUSTED_PROXIES` | `172.31.243.0/24` (= `CASA_SUBNET`) | idem |

Générer `APP_KEY` :

```bash
docker compose --env-file .env.test-local \
  -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.test-local.yml \
  run --rm --no-deps --entrypoint php backend artisan key:generate --show
# → coller dans APP_KEY de backend/.env.test-local
```

### 13.4 Lancement

Alias pratique :

```bash
cd /opt/casa
alias dct='docker compose --env-file .env.test-local -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.test-local.yml'
```

```bash
dct up -d --build
watch -n 3 'dct ps'          # 4× (healthy), Ctrl-C

# Le sous-réseau réel DOIT être égal à TRUSTED_PROXIES :
docker network inspect casa_casa -f '{{(index .IPAM.Config 0).Subnet}}'   # → 172.31.243.0/24

dct exec backend php artisan migrate --force
dct exec backend php artisan casa:seed-referentiel
dct exec backend php artisan casa:create-admin coordination@exemple.ci
```

### 13.5 Sécurité — pas de proxy = pas de confiance dans `X-Forwarded-*`

Ici le navigateur parle **directement** à nginx : **aucun proxy légitime** en
amont. `docker/nginx/casa.test.conf` **remplace** (ne transmet pas) les en-têtes
de proxy :

```nginx
fastcgi_param HTTP_X_FORWARDED_FOR   $remote_addr;   # IP TCP réelle du client
fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;        # "http" — imposé
```

Donc un client qui envoie `X-Forwarded-For: 9.9.9.9` **ne change rien** :
nginx l'écrase avec l'IP TCP réelle avant de passer à Laravel. `TRUSTED_PROXIES`
= le sous-réseau Docker sert **uniquement** à ce que Laravel *lise* l'en-tête
réécrit par nginx (sinon `$request->ip()` = IP du conteneur nginx pour tout le
monde → rate-limiting = un seau unique).

> Le port publié `0.0.0.0:8090` **préserve l'IP source** pour le trafic réseau
> → nginx voit la vraie IP du navigateur. (Un `curl` lancé depuis le serveur
> lui-même verrait la gateway Docker — sans impact.)

### 13.6 Vérifications

```bash
# 1. Sonde + SPA (depuis n'importe quelle machine du réseau)
curl -sI http://172.30.4.200:8090/up | grep -i '^HTTP'       # 200
curl -sI http://172.30.4.200:8090/   | grep -i '^HTTP'       # 200
curl -s  http://172.30.4.200:8090/api/filieres | head -c 60  # JSON

# 2. LOGIN EN HTTP — le cookie de session doit se poser (Secure absent)
curl -s -D - -o /dev/null -H 'Origin: http://172.30.4.200:8090' \
  http://172.30.4.200:8090/sanctum/csrf-cookie | grep -i 'set-cookie'
#   → 2 lignes : XSRF-TOKEN=… et casa-session=… , SANS le mot-clé « secure »

# 3. Anti-usurpation d'IP : 65 requêtes /api/health, X-Forwarded-For forgé
#    TOURNANT → un 429 DOIT quand même apparaître (limite = vraie IP)
for i in $(seq 1 65); do
  curl -s -o /dev/null -w '%{http_code}\n' -H "X-Forwarded-For: 10.$i.$i.$i" \
    http://172.30.4.200:8090/api/health
done | sort | uniq -c
#   Attendu : ~60 × 200 puis ~5 × 429.
```

Puis **dans un navigateur** (une autre machine du réseau) : ouvrir
`http://172.30.4.200:8090`, créer/consulter une candidature, se connecter en
tant qu'admin — **le login doit aboutir** (pas de boucle 419).

### 13.7 Passage à la production réelle

```bash
dct down                       # arrête le mode test (garde les volumes)
```

Puis suivre le **§ 14** (Apache + HTTPS). Points à ne PAS oublier au passage :

- `SESSION_SECURE_COOKIE=true` (obligatoire dès qu'il y a du HTTPS) ;
- `APP_URL` / `SESSION_DOMAIN` / `SANCTUM_STATEFUL_DOMAINS` = le **vrai domaine**
  (sans le `:8090`) ;
- `CASA_BIND_ADDR=127.0.0.1` (le mode Apache : nginx n'écoute plus que sur la
  loopback, Apache le proxifie).

Les **données** (base, pièces) sont dans des volumes Docker (`casa_postgres_data`,
`casa_documents_data`) : elles survivent au `dct down` et sont réutilisées par le
mode Apache (même projet Compose `casa`). Pour repartir de zéro : `dct down -v`.

---

## 14. Déploiement derrière un Apache existant (multi-apps, port dédié)

> **Contexte.** Le serveur Ubuntu héberge déjà d'autres applications, avec
> **Apache** en façade sur les ports 80/443. CASA **ne peut pas** prendre 80/443
> ni terminer le TLS. Solution : CASA tourne en **HTTP local sur un port dédié**
> (8090 par défaut, lié à `127.0.0.1`), et **Apache fait le reverse-proxy
> HTTPS → CASA**. Apache garde la maîtrise du certificat (`certbot`).
>
> **En mots simples, ce que fait Apache** : quand quelqu'un ouvre
> `https://casa.mon-domaine.ci`, Apache déchiffre le HTTPS, ajoute un en-tête
> qui dit à CASA « la requête d'origine était en HTTPS », puis transmet la
> demande à CASA sur `http://127.0.0.1:8090`. CASA répond, Apache renvoie au
> visiteur. CASA n'est jamais joignable directement de l'extérieur.

```
Navigateur ──HTTPS──▶ Apache :443 ──HTTP + X-Forwarded-Proto:https──▶ nginx CASA 127.0.0.1:8090 ──▶ backend/frontend
```

> **Raccourci.** Le script `docker/apache/deploy-behind-apache.sh` fait §§ 14.2 à
> 13.7 en une commande (config, build, migrations, référentiel, vhost, vérifs) :
> ```bash
> cd /opt/casa
> CASA_DOMAIN=casa.mon-domaine.ci CASA_ADMIN_EMAIL=coordination@mon-domaine.ci \
>   bash docker/apache/deploy-behind-apache.sh
> ```
> Les sections ci-dessous détaillent ce qu'il fait, pour un déploiement manuel
> ou du dépannage.

### 14.1 Prérequis

Comme § 1 (Docker + Compose ≥ 2.24, Git), **plus** :

- Apache 2.4.34+ déjà installé et en service (2.4.34 pour la directive `<IfFile>`).
- Un **port TCP local libre** pour CASA (ce guide : `8090`). Vérifier :
  ```bash
  sudo ss -tlnp | grep -E ':8090\b' || echo "8090 libre"
  ```
- Modules Apache : `proxy`, `proxy_http`, `headers`, `rewrite`, `ssl`.
  ```bash
  sudo a2enmod proxy proxy_http headers rewrite ssl
  sudo systemctl reload apache2
  ```
- Un **sous-domaine** (`casa.mon-domaine.ci`) avec un enregistrement DNS **A**
  pointant vers l'IP **publique** du serveur, et les ports 80/443 accessibles
  depuis Internet (nécessaire pour que Let's Encrypt valide le domaine).
- **DNS des conteneurs Docker fonctionnel.** Le *build* (npm / composer / apk)
  doit résoudre les registres publics ; le *runtime* de CASA, lui, n'a besoin
  d'aucun DNS externe (résolveur Docker interne pour `postgres` / `frontend`).
  Tester :
  ```bash
  docker run --rm alpine nslookup registry.npmjs.org
  ```
  Si ça échoue (le build planterait sur une résolution de nom), forcer des
  résolveurs fiables au niveau du démon :
  ```bash
  echo '{ "dns": ["8.8.8.8", "1.1.1.1"] }' | sudo tee /etc/docker/daemon.json
  sudo systemctl restart docker
  ```
  *(Adapter aux résolveurs internes de l'organisation si `8.8.8.8` est bloqué.)*

### 14.2 Récupération du code + configuration

Identiques au **mode A** :

- **§ 3** — cloner le dépôt (ici on suppose `/opt/casa`).
- **§ 4** — créer et remplir les deux `.env.production`. Différences pour ce mode :
  - `.env.production` (racine) : **pas besoin de `TLS_DIR`** (CASA ne gère pas de
    certificat). Ces trois lignes sont déjà dans le modèle — laisser les défauts :
    ```
    CASA_HTTP_PORT=8090
    CASA_BIND_ADDR=127.0.0.1
    CASA_SUBNET=172.31.243.0/24
    ```
  - `backend/.env.production` : `APP_URL=https://casa.mon-domaine.ci`,
    `SESSION_DOMAIN=casa.mon-domaine.ci`,
    `SANCTUM_STATEFUL_DOMAINS=casa.mon-domaine.ci`, `SESSION_SECURE_COOKIE=true`.
    Le HTTPS est réel côté visiteur (assuré par Apache) — les cookies `Secure`
    sont donc corrects.
  - `backend/.env.production` : **`TRUSTED_PROXIES=172.31.243.0/24`** (= `CASA_SUBNET`),
    **jamais `*`** (voir § 14.5 — le serveur héberge d'autres apps, `*` ouvre
    une usurpation d'IP). C'est la valeur du modèle ; le script la pose
    automatiquement.

Alias de commande pour ce mode :

```bash
cd /opt/casa
alias dca='docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.apache.yml'
```

### 14.3 Démarrage de CASA (HTTP local)

```bash
dca up -d --build
watch -n 3 'dca ps'        # attendre 4× (healthy), Ctrl-C
```

`nginx` de CASA écoute maintenant sur **`127.0.0.1:8090`** uniquement — rien
n'est exposé publiquement. Test local :

```bash
curl -s http://127.0.0.1:8090/up | head -c 40 ; echo      # page "up" Laravel
curl -sI http://127.0.0.1:8090/ | grep -i "^HTTP"         # 200 (SPA)

# Le sous-réseau réel DOIT être égal à TRUSTED_PROXIES (§ 14.5) :
docker network inspect casa_casa -f '{{(index .IPAM.Config 0).Subnet}}'
grep '^TRUSTED_PROXIES=' backend/.env.production
```

Base de données + référentiel + premier admin : **identiques aux § 8 et § 9**
(remplacer `dcp` par `dca`) :

```bash
dca exec backend php artisan migrate --force
dca exec backend php artisan casa:seed-referentiel
dca exec backend php artisan casa:create-admin coordination@mon-domaine.ci
```

### 14.4 VirtualHost Apache

Le fichier `docker/apache/casa.vhost.conf` est prêt à l'emploi. Seules **3 lignes**
sont à changer (les `Define` en tête).

```bash
sudo cp /opt/casa/docker/apache/casa.vhost.conf /etc/apache2/sites-available/casa.conf
sudo nano /etc/apache2/sites-available/casa.conf
#   Define CASA_DOMAIN        casa.mon-domaine.ci
#   Define CASA_ADMIN_EMAIL   coordination@mon-domaine.ci
#   Define CASA_UPSTREAM      http://127.0.0.1:8090      (= CASA_BIND_ADDR:CASA_HTTP_PORT)

sudo a2ensite casa
sudo apache2ctl configtest          # doit afficher "Syntax OK"
sudo systemctl reload apache2
```

Ce que contient le vhost :
- un `<VirtualHost *:80>` qui **proxifie tout** vers `http://127.0.0.1:8090`,
  ajoute `X-Forwarded-Proto` / `X-Forwarded-Port` / `X-Forwarded-For` (via
  `mod_headers` + `mod_proxy_http`), `ProxyPreserveHost On` (le `Host:` d'origine
  arrive à CASA — indispensable pour Sanctum) ;
- un `<VirtualHost *:443>` **encadré par `<IfFile>`** : il ne s'active **que
  lorsque le certificat existe**. Il ajoute l'en-tête HSTS (Apache possède le
  TLS). Tant qu'il n'y a pas de certificat, seul le `:80` répond.

À ce stade, `http://casa.mon-domaine.ci/up` répond déjà (en clair). ⚠️ **Le
login ne marchera qu'en HTTPS** (cookies `Secure`) — passer au § 14.6.

### 14.5 `TRUSTED_PROXIES` — durcissement (serveur mutualisé)

`bootstrap/app.php` : `$middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'))`.
Les en-têtes `X-Forwarded-*` sont dans la liste de confiance par défaut de
Laravel.

**Pourquoi `*` est dangereux ICI.** Apache **ajoute** (n'écrase pas) l'IP du
client à `X-Forwarded-For`. Avec `TRUSTED_PROXIES=*`, Laravel fait confiance à
**toute** la chaîne — donc à une valeur que le client aurait **lui-même
pré-remplie** :

```
Client envoie:  X-Forwarded-For: 9.9.9.9
Apache ajoute:  X-Forwarded-For: 9.9.9.9, <vraie IP client>
Laravel (*)  →  « IP client = 9.9.9.9 »   ← USURPÉE
```

Conséquences concrètes :

- **Contournement du rate-limiting keyé sur l'IP** — `casa-public` (60/min,
  `/api/filieres` + `/api/health`), `login` (5/min) et `register` (3/min + 20/j).
  Un attaquant qui fait tourner la fausse IP défait l'anti-brute-force et
  l'anti-bot d'inscription.
- **Logs applicatifs pollués** — `AuthController` journalise `$request->ip()`
  lors d'une tentative de connexion sur un compte désactivé.

*(Le journal d'audit métier `journal_audit` — ADR-12, immuable — ne stocke pas
d'IP aujourd'hui, il n'est donc pas directement concerné ; mais si une colonne
IP y est ajoutée un jour, `TRUSTED_PROXIES` resserré la rend fiable dès le
départ.)*

Sur un serveur qui héberge d'autres applications, on ne prend pas ce risque.

**La valeur correcte.** Le sous-réseau Docker de CASA, **figé** par
`docker-compose.prod.yml` (`CASA_SUBNET`, défaut `172.31.243.0/24`) :

```
TRUSTED_PROXIES=172.31.243.0/24
```

Laravel remonte alors `X-Forwarded-For` **de droite à gauche** et **s'arrête au
premier hop hors de ce sous-réseau** :

```
Chaîne reçue par Laravel :  9.9.9.9 , <vraie IP client> , <vraie IP client>
REMOTE_ADDR = 172.31.243.x (nginx CASA)     → dans le /24 → sauté
… <vraie IP client> (à droite)             → HORS du /24 → STOP. IP client = celle-ci.
   9.9.9.9 (pré-injecté par le client)      → jamais atteint → IGNORÉ
```

**Prouvé en local** (mode Apache, `docker network inspect` = `172.31.243.0/24`) :
65 requêtes `/api/health` avec un `X-Forwarded-For` **forgé tournant** →
`60 × 200` puis `5 × 429` (le limiteur `casa-public` a bien keyé sur la vraie
IP). Avec `TRUSTED_PROXIES=*` : **65 × 200** — l'usurpation contourne la limite.

Vérifier après `up` que le réseau a bien ce sous-réseau :

```bash
docker network inspect casa_casa -f '{{(index .IPAM.Config 0).Subnet}}'   # → 172.31.243.0/24
```

Si ce `/24` est déjà pris sur le serveur : choisir une autre valeur (ex.
`10.201.0.0/24`) et la répercuter aux **trois** endroits — `CASA_SUBNET`
(`.env.production`), `TRUSTED_PROXIES` (`backend/.env.production`),
`set_real_ip_from` (`docker/nginx/casa.apache.conf`) — puis
`dca down && dca up -d`.

**La détection de l'IP client reste correcte** (c'est prouvé par la chaîne
ci-dessus) :

| | Résultat avec `TRUSTED_PROXIES=172.31.243.0/24` |
|---|---|
| `$request->ip()` (rate-limiting, logs) | **la vraie IP client** transmise par Apache — un `X-Forwarded-For` forgé est ignoré |
| `$request->isSecure()` | `true` (via `X-Forwarded-Proto: https` qu'Apache **écrase** — non usurpable, même avec `*`) |
| `$request->getHost()` | `casa.mon-domaine.ci` (via `ProxyPreserveHost On`) → Sanctum matche l'`Origin` |

Rien à changer dans `config/session.php` / `config/sanctum.php` (100 % `env()`).
En **mode A** (§ 1-12), la même valeur `172.31.243.0/24` s'applique : le nginx de
CASA y **écrase** `X-Forwarded-For` (`$remote_addr`) et pose `HTTPS on` en
fastcgi, donc rien n'est usurpable — mais on garde la valeur resserrée par
cohérence et défense en profondeur.

### 14.6 Certificat Let's Encrypt

```bash
sudo apt install -y certbot
sudo certbot certonly --apache -d casa.mon-domaine.ci \
     -m coordination@mon-domaine.ci --agree-tos --no-eff-email
sudo systemctl reload apache2       # <IfFile> détecte le cert -> :443 s'active + :80 redirige
```

Rechargement automatique d'Apache au renouvellement (une fois) :

```bash
echo 'systemctl reload apache2' | sudo tee /etc/letsencrypt/renewal-hooks/deploy/reload-apache.sh
sudo chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-apache.sh
sudo certbot renew --dry-run
```

### 14.7 Vérifications

```bash
# 1. Redirection + certificat
curl -sI http://casa.mon-domaine.ci/  | grep -iE '^HTTP|^location'   # 301 -> https
curl -sI https://casa.mon-domaine.ci/ | grep -i '^HTTP'              # 200

# 2. CASA voit bien du HTTPS (schéma transmis) : la sonde /up passe et le
#    /api renvoie du JSON, pas une redirection
curl -s https://casa.mon-domaine.ci/up | head -c 20 ; echo
curl -s https://casa.mon-domaine.ci/api/filieres | head -c 60 ; echo

# 3. En-têtes : HSTS vient d'Apache, les 5 autres du nginx de CASA
curl -sI https://casa.mon-domaine.ci/ | grep -iE 'strict-transport|content-security|x-frame|x-content-type|referrer-policy|permissions-policy'

# 4. Login admin (nécessite l'en-tête Origin, cf. § 10.9) — objet utilisateur, jamais de hash
curl -sc /tmp/j https://casa.mon-domaine.ci/sanctum/csrf-cookie -o /dev/null
XSRF=$(awk '/XSRF-TOKEN/{print $7}' /tmp/j | perl -pe 's/%([0-9A-Fa-f]{2})/chr hex $1/ge')
curl -s -b /tmp/j -c /tmp/j -X POST https://casa.mon-domaine.ci/api/login \
  -H 'Content-Type: application/json' -H 'Origin: https://casa.mon-domaine.ci' \
  -H "X-XSRF-TOKEN: $XSRF" -d '{"email":"coordination@mon-domaine.ci","password":"VOTRE_MOT_DE_PASSE"}' | head -c 200

# 5. Anti-usurpation d'IP (§ 14.5) : un X-Forwarded-For forgé et TOURNANT ne doit
#    PAS permettre de dépasser la limite (le limiteur key sur la VRAIE IP).
#    /api/health est limité à 60/min/IP. On envoie 65 requêtes avec un XFF
#    différent à chaque fois : un 429 DOIT quand même apparaître.
for i in $(seq 1 65); do
  curl -s -o /dev/null -w '%{http_code}\n' -H "X-Forwarded-For: 10.$i.$i.$i" \
    https://casa.mon-domaine.ci/api/health
done | sort | uniq -c
#   Attendu : ~60 × "200" PUIS ~5 × "429".  (Si 65 × "200" → TRUSTED_PROXIES
#   est trop large, l'usurpation marche : revoir § 14.5.)
```

Puis, **dans un navigateur** : ouvrir `https://casa.mon-domaine.ci`, se connecter,
vérifier l'absence d'erreur CSP dans la console.

### 14.8 Ce qui change pour la maintenance (§ 11)

- **Mise à jour** : `git fetch --tags && git checkout <tag> && dca up -d --build`
  puis `dca exec backend php artisan migrate --force`.
- **Sauvegardes / restauration / logs** : identiques au § 11, en remplaçant
  `dcp` par `dca`.
- **Après un changement d'`.env.production`** : `dca up -d --force-recreate backend`.
- **Cohabitation** : le projet Compose s'appelle `casa` (réseau `casa_casa`,
  conteneurs `casa-*`) — aucun risque de collision avec les autres piles Docker
  du serveur. Seul le port `8090` (loopback) est pris ; Apache et les autres
  apps ne sont pas touchés.
- **« Si CASA ne répond plus via Apache »** :
  | Symptôme | Cause | Correctif |
  |---|---|---|
  | Apache renvoie **502 Bad Gateway** | CASA (`:8090`) est arrêté ou pas `healthy` | `dca ps` ; `dca up -d` ; `dca logs -f nginx` |
  | Login en **boucle** / **419** | `SESSION_DOMAIN` / `SANCTUM_STATEFUL_DOMAINS` ≠ domaine servi, ou `X-Forwarded-Proto` absent (module `headers` non activé) | vérifier les 2 vars = `casa.mon-domaine.ci` ; `a2enmod headers` ; `--force-recreate backend` |
  | `$request->ip()` (logs, rate-limiting) = **IP interne** `172.31.243.x` au lieu de l'IP client, et/ou `$request->isSecure()` faux (URLs `http://` générées) | `docker network inspect casa_casa` ≠ `TRUSTED_PROXIES` (souvent après avoir changé `CASA_SUBNET` sans `dca down`) | aligner `CASA_SUBNET` / `TRUSTED_PROXIES` / `set_real_ip_from` sur le sous-réseau **réel** ; `dca down && dca up -d` |
  | Rate-limiting **contournable** avec un `X-Forwarded-For` forgé (test § 14.7 n°5 → 65 × 200) | `TRUSTED_PROXIES` trop **large** (`*` ou un `/8`) → Laravel remonte trop loin dans la chaîne | remettre exactement `= CASA_SUBNET` ; `dca up -d --force-recreate backend` |
  | `build` échoue sur `getaddrinfo` / `Could not resolve host` (npm, composer) | DNS des conteneurs Docker cassé | `/etc/docker/daemon.json` → `{"dns":["8.8.8.8","1.1.1.1"]}` ; `sudo systemctl restart docker` ; relancer (§ 14.1) |
  | Page blanche, **erreurs CSP** | une lib front charge une ressource externe | ajuster la CSP dans `docker/nginx/casa.apache.conf` puis `dca exec nginx nginx -s reload` |
  | `apache2ctl configtest` : **AH00526** sur `<IfFile>` | Apache < 2.4.34 | mettre à jour, ou retirer les blocs `<IfFile>` et gérer le `:443` manuellement |

---

## Récapitulatif de la vérification prod-like (Lot 9c)

Suivi de ce document sur la machine de développement, domaine `casa.localhost`,
certificat auto-signé (`gen-selfsigned.sh`), fichiers `.env.production` dédiés :

- `dcp up -d --build` → 4 services `healthy`, entrypoint visible dans les logs
  (`[entrypoint] Reconstruction des caches Laravel…`).
- `dcp exec backend php artisan migrate --force` → migrations appliquées.
- `dcp exec backend php artisan casa:seed-referentiel` → 5 filières + grille + 6
  types de doc + 1 campagne, **0 compte** ; 2ᵉ passage → « référentiel déjà
  présent », rien touché.
- `dcp exec backend php artisan casa:create-admin` → compte créé, ligne d'audit,
  login navigateur OK.
- Checklist § 10 : 301 http→https, 6 en-têtes sur `/` et `/api`, `/storage` +
  `/.env` → 404, `php artisan about` → Config/Routes/Events **CACHED**, login
  admin curl → objet utilisateur sans `mot_de_passe_hash`, **0 violation CSP** au
  navigateur.
- `dcp up -d --force-recreate backend` après édition de `TRUSTED_PROXIES` →
  nouvelle valeur bien reprise dans `config:cache` (piège ADR-28 neutralisé).
