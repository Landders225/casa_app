# CASA — Application de production (Lot 0 : fondations & conception)

Monorepo de l'application de production CASA (sélection de bénéficiaires — CCI-CI / FADV / AICS), en cours d'industrialisation à partir de la maquette front-end `../App_maquette` (source de vérité fonctionnelle, **non modifiée**, lue uniquement pour spécifier ce dépôt).

> **Périmètre de ce lot (Lot 0).** Squelette de dépôt, environnement Docker fonctionnel (4 services), conception MERISE (MCD/MLD) et UML (cas d'usage + séquences), décisions d'architecture. **Aucune logique métier, aucun endpoint fonctionnel, aucun écran React** — voir `docs/`.

## Structure

```
casa-app/
├── backend/     Laravel 12 (API REST), Sanctum installé (config, pas de route métier)
├── frontend/    React (Vite), squelette par défaut
├── docker/
│   └── nginx/default.conf   reverse proxy unique : /api -> backend, / -> frontend
├── docs/        conception (dictionnaire de données, MCD, MLD, UML, ADR)
├── docker-compose.yml
└── .env.example
```

## Stack

PostgreSQL 16 · Laravel (PHP 8.3, PHP-FPM) · React (Vite, build servi par un nginx interne au conteneur) · Sanctum (SPA, cookies same-origin) · reverse proxy nginx unique · Docker Compose.

## Démarrage (vérifié)

```bash
# 1. Variables d'environnement
cp .env.example .env
cp backend/.env.example backend/.env      # déjà fourni pré-rempli dans ce dépôt pour ce lot
cp frontend/.env.example frontend/.env    # idem

# 2. Build + démarrage des 4 services
docker compose build
docker compose up -d

# 3. Si backend/.env vient d'être recréé depuis .env.example (APP_KEY vide) :
docker compose exec backend php artisan key:generate --force

# 4. Migrations (squelette Laravel/Sanctum par défaut — aucune table métier
#    dans ce lot, cf. docs/mld.md pour le schéma prévu au lot suivant)
docker compose exec backend php artisan migrate --force

# 5. Vérification : les 4 conteneurs doivent être "healthy"
docker compose ps
```

**Point d'entrée unique** : http://localhost:8080 (frontend). API : http://localhost:8080/api/\*. Sonde de santé backend : http://localhost:8080/up.

### État vérifié à la livraison de ce lot

```
NAME                  STATUS
casa-app-postgres-1   Up (healthy)
casa-app-backend-1    Up (healthy)
casa-app-frontend-1   Up (healthy)
casa-app-nginx-1      Up (healthy)
```
`php artisan migrate` exécuté avec succès contre Postgres (tables Laravel par défaut + `personal_access_tokens` de Sanctum) — la chaîne complète (nginx → backend PHP-FPM → Postgres, et nginx → frontend) est confirmée fonctionnelle de bout en bout.

## Documentation de conception (`docs/`)

| Fichier | Contenu |
|---|---|
| `dictionnaire-donnees.md` | Toutes les entités/attributs, avec marqueur de confidentialité 🟢🟡🔴 par colonne — **checklist à cocher lors de l'écriture des API Resources**. |
| `mcd.md` | Modèle Conceptuel de Données MERISE (associations, cardinalités) + diagramme Mermaid. |
| `mld.md` | Modèle Logique de Données — DDL PostgreSQL prêt à traduire en migrations Laravel (pas des migrations réelles dans ce lot). |
| `uml-cas-usage.md` | 3 diagrammes de cas d'usage (Candidat / Évaluateur / Administrateur), PlantUML. |
| `uml-sequences.md` | 3 séquences critiques (soumission+éligibilité serveur ; verrouillage+correction ; publication et visibilité candidat avant/après), Mermaid. |
| `ADR.md` | Décisions d'architecture (contexte → décision → conséquence), y compris la dérivation serveur du statut public (ADR-03). |

## Rappels de règles non négociables (détail : `docs/ADR.md`)

1. Grille/score/notes/classement/motifs internes : jamais renvoyés au candidat, par aucune API — autorisation vérifiée côté serveur.
2. Avant publication : le candidat ne voit qu'un statut neutre, quel que soit l'état interne réel. Après publication : décision finale + motif communicable éventuel, jamais le score.
3. Éligibilité 2 sites = logique OR.
4. Verrouillage réel en base ; correction exceptionnelle réservée admin, motif obligatoire.
5. Journal d'audit persistant et immuable (applicatif).
6. Barème en base, versionné — jamais codé en dur.

## Prochains lots (hors périmètre de celui-ci)

Migrations réelles + seeders (grille officielle en données), modèles Eloquent + policies, endpoints API (auth, candidature, évaluation, entretien, classement, publication, audit), écrans React par rôle, tests automatisés (dont un test dédié à la non-fuite de données confidentielles vers le rôle candidat).
