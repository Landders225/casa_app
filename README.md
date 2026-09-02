# CASA — Application de production

Monorepo de l'application de production CASA (sélection de bénéficiaires — CCI-CI / FADV / AICS), en cours d'industrialisation à partir de la maquette front-end `../App_maquette` (source de vérité fonctionnelle, **non modifiée**, lue uniquement pour spécifier ce dépôt).

> **Avancement.**
> - **Lot 0** — squelette de dépôt, Docker (4 services), conception MERISE/UML, ADR (`docs/`).
> - **Lot 1** — schéma PostgreSQL réel (30 tables métier), seeders de référence (barème, filières, campagne, comptes démo), trigger append-only `journal_audit`.
> - **Lot 2** — authentification Sanctum SPA (session-cookie), recâblage `User` → table `utilisateur`, rôles (middleware `role:` + Gates ADR-10).
> - **Lot 3** — candidature (rôle candidat, backend seul) : **3a** formulaire & brouillon, **3b** upload sécurisé des pièces (hors webroot, ADR-11), **3c** soumission + éligibilité 100 % serveur avec communication différée (séquence a, ADR-03/06).
> - **Lot 4** — espace évaluateur (rôles évaluateur/administrateur, backend seul) : **4a** consultation des dossiers affectés + vérification (nationalité, diplôme) déclenchant les critères éliminatoires évaluateur ; **4b** notation du volet Dossier /65 calculée 100 % serveur (`ServiceScoring` lit le barème en base, portage fidèle de `scoring.js`), brouillon → validation → **verrouillage réel** (snapshot figé + `grille_id`, jamais recalculé — ADR-04) ; **4c** volet Entretien /35 : planification minimale, présence, 12 sous-notes, calcul serveur, brouillon → validation → verrouillage (`entretien.statut = 'valide'`). Resources évaluateur distinctes, aucune fuite vers le candidat. Toujours **aucun écran React**.
> - **Lot 5** — classement, décisions & publication (backend seul) : **5a** score final /100 (somme des snapshots figés), classement par filière (`ServiceClassement` — quotas en base, départage `scoring.js` + tie-break déterministe D-5a-1), attribution `retenu` / `liste_attente` / `non_retenu`, persistance de `decision_candidature` — **administrateur strict**, sans publication ; **5b** l'**acte de publication** (`POST .../publier`, irréversible) bascule `StatutPublicResolver` sur sa branche « publication existe » : le candidat voit alors sa `decision` + `motif_communicable` (si saisi) — et **rien d'autre**, jamais son score/rang/motif interne. Un non-éligible voit un `non_retenu` générique **indiscernable** d'un non-retenu ordinaire (D-5b-1). C'est le basculement que toute l'architecture prépare depuis le Lot 0 (ADR-03, séquence (c)).

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

# 4. Schéma + données de référence (Lot 1)
docker compose exec backend php artisan migrate:fresh --seed --force

# 5. Vérification : les 4 conteneurs doivent être "healthy"
docker compose ps
```

**Point d'entrée unique** : http://localhost:8080 (frontend). API : http://localhost:8080/api/\*. Sonde de santé backend : http://localhost:8080/up.

## Authentification (Lot 2)

Sanctum en mode SPA (cookie de session same-origin, **jamais** de token Bearer — cf. `docs/ADR.md` ADR-01). Séquence côté client : `GET /sanctum/csrf-cookie` (dépose `XSRF-TOKEN`), puis toute requête mutante renvoie ce jeton dans l'en-tête `X-XSRF-TOKEN`.

| Méthode | Route | Rôle | Description |
|---|---|---|---|
| GET | `/sanctum/csrf-cookie` | public | Dépose le cookie CSRF. |
| POST | `/api/login` | public (throttlé 5/min/email+IP) | `{email, password}` → ouvre la session, renvoie l'utilisateur (liste blanche, jamais `mot_de_passe_hash`). Échec 100 % générique (compte inexistant / mauvais mot de passe / compte désactivé : même `422`). |
| POST | `/api/logout` | authentifié | Invalide la session. |
| GET | `/api/me` | authentifié | Utilisateur courant + profil (`candidat` ou `membre_equipe`). |
| GET | `/api/ping-candidat` | `candidat` | Démonstration du filtrage par rôle. |
| GET | `/api/ping-evaluateur` | `evaluateur` **ou** `administrateur` (ADR-10) | idem. |
| GET | `/api/ping-admin` | `administrateur` strict | idem. |

**Comptes de démonstration** (seedés, mot de passe `Demo2026!`) : `candidat@casa-demo.ci`, `evaluateur@casa-demo.ci`, `admin@casa-demo.ci`.

## Tests

Le schéma est spécifique à PostgreSQL (trigger append-only, `CHECK`, `uuid`) : les tests tournent contre une **vraie base PostgreSQL** `casa_test`, pas SQLite.

```bash
# 1. Créer la base de test (reproductible ; --fresh pour la recréer)
docker compose exec backend php artisan casa:test-db

# 2. Lancer la suite (RefreshDatabase applique les migrations réelles)
docker compose exec backend php artisan test
```

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
