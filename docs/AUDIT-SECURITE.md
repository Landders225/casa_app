# Audit de sécurité défensive — CASA

> **Objet.** Revue systématique des surfaces de sécurité de la plateforme CASA
> avant mise en ligne, dans l'esprit d'un test d'intrusion **défensif** : on
> tente de contourner nos propres protections pour prouver qu'elles tiennent.
> Aucun outil offensif n'est produit — on durcit CASA.
>
> **Méthode.** Pour chaque surface : (a) constat de l'état des défenses, (b) test
> automatisé qui le prouve, (c) correction de tout écart. La plupart des surfaces
> étaient déjà couvertes depuis les lots antérieurs ; ce document **confirme
> systématiquement** et recense les rares manques, désormais comblés.
>
> **Date.** Lot 10 — 2026-09-07. **Périmètre** : le code de CASA (backend Laravel,
> frontend React, configuration Docker/nginx). Hors périmètre : sécurité du
> serveur hôte (durcissement OS, SSH, pare-feu réseau — voir `docs/DEPLOIEMENT.md`).

---

## Synthèse

| # | Sujet | Gravité | État |
|---|---|---|---|
| **1** | Comptes de démonstration affichés sur la page de connexion publique | Moyen | **Corrigé** — retirés du code, absence prouvée dans le bundle + en CI |
| **2** | Aucune limitation de débit hors `login`/`register` | Moyen | **Corrigé** — 4 limiteurs ajoutés (global authentifié, upload, création, routes publiques) |
| **3** | Trigger d'immuabilité du journal d'audit jamais testé | Faible | **Corrigé** — `JournalAuditImmuableTest` (UPDATE/DELETE/TRUNCATE rejetés) |
| **4** | En-têtes révélant les versions de PHP et nginx | Faible | **Corrigé** — `expose_php = Off`, `server_tokens off` |
| **5** | Favicon absent (cosmétique) | — | **Corrigé** — favicon aux couleurs CASA (SVG + PNG de secours) |
| **R1** | Oracle 403/404 sur les routes à identifiant (la ressource existe ou non) | Très faible | **Résiduel accepté** — UUID 122 bits non énumérables |
| **R2** | Pas de vérification « mot de passe compromis » (HIBP) | Faible | **Résiduel** — déjà tracé (`POINTS-OUVERTS.md`) |
| **R3** | Pas de vérification d'e-mail à l'inscription | Faible | **Résiduel** — déjà tracé (pas d'infra e-mail) |

**Aucune faille grave ni critique.** Les invariants de confidentialité (règle
reine, ADR-03) et d'autorisation résistent à toutes les tentatives de contournement.

---

## PARTIE 1 — Corrections concrètes

### 1. Retrait des comptes de démonstration de la page de connexion

**Constat.** `LoginPage.jsx` affichait un panneau « Comptes de démonstration »
listant trois adresses (`candidat@` / `evaluateur@` / `administrateur@casa-demo.ci`)
et leur mot de passe commun, avec des boutons « Utiliser ». Ces comptes sont
**privilégiés** (dont un administrateur) et seedés en développement par
`ComptesDemoSeeder`. Les afficher sur une page **publique** revient à les publier
dans un bundle JavaScript **téléchargeable** — un masquage CSS n'aurait rien
changé (leçon du Lot 8b-1).

**Correction.**
- Suppression **du code** : constantes `DEMO_ACCOUNTS` / `DEMO_PASSWORD`, fonction
  `fillDemo`, panneau JSX, règles CSS associées. Suppression aussi du widget mort
  « Mode démonstration » (`#casa-demo-fab` / `.csw-*`) hérité de la maquette,
  jamais rendu par l'application.
- **Triple garde-fou** contre la réintroduction :
  1. `src/pages/auth/__tests__/noDemoCreds.test.js` — scanne **tout `src/`** (hors
     `__tests__/`) et échoue sur toute occurrence de `casa-demo.ci`, `Demo2026`,
     `DEMO_ACCOUNT`. 113 fichiers scannés, tous propres.
  2. `LoginPage.test.jsx` — un test dédié vérifie qu'aucun identifiant ni bouton
     « Utiliser » n'apparaît au rendu.
  3. **CI** (`.github/workflows/ci.yml`, job `frontend`) — `grep` du **`dist/`
     construit** après `npm run build` : échec du pipeline si un identifiant de
     démonstration y figure.
- **Preuve bundle** (exécutée pendant l'audit) : `npm run build` puis
  `grep -rIE "casa-demo|Demo2026|DEMO_ACCOUNT" dist/` → **aucune occurrence**.

**Production.** `casa:seed-referentiel` (commande de déploiement, Lot 9c) ne joue
**pas** `ComptesDemoSeeder` : en production, ces comptes **n'existent pas**.
Confirmé par `SeedReferentielTest::test_amorce_le_referentiel_sans_les_comptes_demo`
(`assertDatabaseCount('utilisateur', 0)`).

### 2. Favicon

**Constat.** Aucun `<link rel="icon">` dans `index.html` (perdu avec le template
Vite au Lot 8a) → onglet sans identité, requêtes `/favicon.ico` en 404.

**Correction.** Favicon aux couleurs de la charte (teal `#0E5C53`, pousse
stylisée) :
- `public/favicon.svg` — vectoriel, net à toute taille ;
- `public/favicon-32.png` + `public/apple-touch-icon.png` — **secours** pour les
  navigateurs sans support SVG (Safari < 16.4) et l'écran d'accueil iOS, générés
  par `scripts/gen-favicon.mjs` (Node `zlib` seul, aucune dépendance) ;
- `<link>` + `<meta name="theme-color">` dans `index.html`.

Compatible CSP (`img-src 'self'`).

---

## PARTIE 2 — Audit systématique

### Authentification

**Défenses en place.**
- Hachage **bcrypt coût 12** (`BCRYPT_ROUNDS=12` dans les deux `.env` exemples ;
  4 en test pour la vitesse). Le modèle `User` applique le cast `hashed` — jamais
  de mot de passe en clair persisté.
- **Limitation** : `throttle:login` = 5 tentatives/min par (e-mail + IP) ;
  `throttle:register` = 3/min **et** 20/jour par IP.
- **Message d'échec 100 % générique** (`AuthController::login`) : e-mail inconnu,
  mauvais mot de passe **et compte désactivé** renvoient le même 422
  (« E-mail ou mot de passe incorrect. ») — aucune énumération de comptes, aucun
  indice « ce compte existe mais est désactivé ». L'événement « compte désactivé »
  est seulement journalisé côté serveur.
- **Session régénérée** au login **et** à l'inscription (`session()->regenerate()`) ;
  **invalidée + jeton régénéré** au logout (`invalidate()` + `regenerateToken()`).
- Pas de « remember me » (session SPA same-origin, ADR-01) ; pas de jeton Bearer.

**Tests qui le prouvent.** `AuthTest.php` — succès + `derniere_connexion_le`,
jamais de hash dans la réponse, échec mot de passe / e-mail inconnu / compte
désactivé **identiques**, `test_login_is_rate_limited` (429 à la 6ᵉ),
`test_logout_invalidates_the_session`. `Security/ConfigDurcieTest` — les exemples
d'environnement portent bien `BCRYPT_ROUNDS=12`.

**Écart trouvé.** Aucun.

### Autorisation

**Défenses en place.**
- Middleware `role:` (`EnsureUserHasRole`) : **401** si non authentifié, **403**
  si le rôle ne correspond pas. `admin ⊇ évaluateur` (ADR-10) exprimé
  directement : les routes évaluateur déclarent `role:evaluateur,administrateur`.
- **Isolation par propriétaire** via les Policies : accéder au dossier / à la
  pièce / à l'expérience d'autrui renvoie **404** (`denyAsNotFound`), jamais 403 —
  on ne confirme pas l'existence d'une ressource qu'on n'a pas le droit de voir.
- Le profil candidat (`/api/candidat/profil`) n'a **aucun paramètre d'URL** : il
  agit toujours sur `user()->candidat` — surface d'attaque vers autrui nulle.

**Tests qui le prouvent.**
- **`Security/MatriceAutorisationTest`** (nouveau, **exhaustif**) — introspecte la
  table de routage, repère **chaque** route `role:`-gardée (≥ 35) et vérifie pour
  chacune : rôle interdit → **403 ou 404** (jamais un 2xx ni un code « traité »),
  invité → **401**, rôle autorisé → jamais 401/403. Plus un test à **ressource
  réelle** prouvant le **403 exact** sur les 8 actes admin les plus sensibles
  (correction, élimination, publication, classement, motifs…).
- `RoleAccessTest` (matrice des routes de démonstration), `IsolationCandidatureTest`,
  `IsolationPieceTest`, `IsolationEvaluateurTest`, `NonFuiteVersCandidatTest`.

**Écart trouvé.** Aucun trou d'autorisation. **Nuance** documentée (→ R1) : sur
les routes à identifiant, un rôle interdit reçoit **404** quand l'id est
inexistant (le middleware `SubstituteBindings` du groupe s'exécute avant `role:`)
et **403** quand l'id est réel. Les deux réponses sont non-divulgantes.

### Fuite de données (règle reine, ADR-03)

**Défenses en place.**
- Toutes les Resources candidat sont en **liste blanche stricte**
  (`CandidatureCandidatResource`, `CandidatResource`, `UserResource`…) : les
  colonnes 🔴 (`statut_interne`, `statut_eligibilite_interne`, `dossier_verrouille*`,
  `evaluateur_id`, score, rang, `motif_interne`, critères éliminatoires,
  `commentaire_evaluateur`) ne sont **jamais** sérialisées vers un candidat.
- `StatutPublicResolver` est le **seul** composant autorisé à lire `statut_interne`
  et `decision_candidature` pour construire une réponse candidat. Avant
  publication : statut neutre unique. Après : décision + motif communicable
  éventuel, **jamais** le score. Un non-éligible est **indiscernable** d'un
  non-retenu ordinaire.
- Le frontend ne reproduit **aucun barème** : la structure des questions est
  transcrite sans points, vérifiée par un scan de source **et** un grep du bundle.

**Tests qui le prouvent.** `SoumissionAntiFuiteTest`, `Candidat/ResultatApresPublicationTest`,
`Evaluateur/NonFuiteVersCandidatTest`, `Admin/NonFuiteClassementTest`,
`FilieresPubliquesTest` (route publique = 4 champs 🟢). Côté front :
`noScoringFormula.test.js` (×2 arbres), et — depuis ce lot — `noDemoCreds.test.js`
+ grep `dist/` en CI. Re-exécutés après le retrait des comptes démo : verts.

**Écart trouvé.** Aucun.

### Limitation de débit — **écart n°2, corrigé**

**Constat.** Seuls `/login` et `/register` étaient limités. Un compte authentifié
pouvait **marteler n'importe quel endpoint** — en particulier le **dépôt de
pièces** (analyse MIME `finfo` + écriture disque à chaque requête) et la
**création de candidatures** (brouillons en masse). Les routes **publiques**
(`/api/health`, `/api/filieres`), hors du groupe authentifié, n'avaient **aucune**
limite — seule porte ouverte à un anonyme.

**Correction.** Quatre limiteurs nommés (`AppServiceProvider`) :

| Limiteur | Débit | Portée | Clé |
|---|---|---|---|
| `casa-public` | **60 / min** | `/api/health`, `/api/filieres` | IP |
| `casa-api` | **120 / min** | tout le groupe `auth:sanctum` (filet global) | id utilisateur (repli IP) |
| `casa-uploads` | **40 / min** | dépôt de pièces + justificatifs d'expérience | id utilisateur |
| `casa-candidatures` | **12 / min** | `POST /api/candidatures` | id utilisateur |

**Calibrage — ne casse aucun usage légitime.** Le parcours de candidature le plus
intense fait ~40 requêtes réparties sur 10+ minutes. 120/min laisse un facteur
~15. Les **actes uniques** (publier un classement, valider une évaluation) ne
consomment qu'**une** requête : un administrateur ne peut jamais recevoir « trop
de requêtes » sur un acte irréversible. La vraie défense des actes admin reste le
**rôle strict + le journal d'audit immuable**, pas un compteur — d'où l'absence
de limiteur dédié sur ces routes (le filet global suffit).

**Tests qui le prouvent.** `Security/RateLimitingTest` — la 61ᵉ requête publique →
429 ; en-têtes `X-RateLimit-Limit` = 60 / 120 / 40 ; la 13ᵉ création → 429 ;
**40 sauvegardes de réponses d'affilée passent toutes** (preuve que le wizard ne
touche jamais la limite) ; `login` reste limité indépendamment.
Non-régression : la suite E2E d'intégration (parcours complet inscription →
retenu) reste verte.

### Upload de pièces

**Défenses en place** (Lot 3b, ADR-11).
- Type validé **par le contenu** (`finfo` / Symfony `mimetypes`), pas par
  l'extension ni le `Content-Type` déclaré ; garde-fou `extensions` en plus.
- Nom de fichier **serveur** : `{candidature_id}/{uuid}.{ext}`, l'extension
  dérivant du MIME détecté. Nom d'origine **assaini** (`basename`, pas de `..`).
- Stockage **hors webroot** (`storage/app/private/`) ; téléchargement uniquement
  via une route Laravel avec policy propriétaire. `location ^~ /storage/` → 404
  côté nginx.
- Taille : `max:10240` (10 Mo) applicatif, `client_max_body_size 13m` nginx.

**Tests qui le prouvent.** `Candidat/SecuriteUploadTest` — exécutable déguisé en
PDF, contenu falsifié, extension `.exe`, dépassement de taille, nom traversant
`../../../../etc/passwd`, extension dérivée du MIME et non du nom. Tous rejetés
en 422, **aucun octet écrit**.

**Écart trouvé.** Aucun. (La limite de débit `casa-uploads` ajoutée au titre du
n°2 renforce cette surface.)

### Injection SQL

**Constat.** Audit du code : `app/` ne contient **aucune** requête brute —
`DB::raw`, `whereRaw`, `selectRaw`, `orderByRaw`, `havingRaw`, `DB::statement`,
`DB::unprepared`, `fromRaw` : **zéro occurrence**. Tout passe par Eloquent / le
Query Builder, qui **paramètrent** systématiquement les valeurs. Le SQL brut
n'existe que dans `database/migrations/` (chaînes 100 % statiques : définition de
`CHECK`, triggers, `DEFAULT gen_random_uuid()` — jamais d'entrée utilisateur).

**Test qui le prouve.** `Security/PasDeSqlBrutTest` — **garde exécutable** :
scanne `app/` (hors commentaires) et échoue si un de ces motifs réapparaît. Si un
besoin légitime survient un jour, il devra être whitelisté explicitement **dans
ce test**, avec la preuve que l'entrée est bindée.

**Écart trouvé.** Aucun.

### CSRF et en-têtes HTTP

**Défenses en place.**
- **Cycle Sanctum SPA** : `GET /sanctum/csrf-cookie` pose `XSRF-TOKEN` ; toute
  mutation renvoie ce jeton dans `X-XSRF-TOKEN`. Élucidé au Lot 9a (le « 419 »
  observé était un artefact de script `curl`, pas un défaut d'auth — prouvé par
  bisection).
- **6 en-têtes de sécurité** en production (`casa.prod.conf`, `add_header … always`) :
  HSTS (1 an + `includeSubDomains`), `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `Permissions-Policy`, **CSP stricte** (`script-src 'self'`, aucun `unsafe-eval`,
  aucun CDN).
- **CORS** scellé à `env(APP_URL)` (jamais `*`), `supports_credentials: true`.
- Cookies : `Secure` + `HttpOnly` + `SameSite=lax` en production (100 % `env()`).

**Tests qui le prouvent.** `AuthTest::test_csrf_cookie_endpoint_is_reachable` ;
`Security/ConfigDurcieTest` (6 en-têtes présents, CSP sans `unsafe-eval`, CORS ≠ `*`) ;
preuve prod-like du Lot 9b (`prodlike9b.sh` 31/0 + navigateur : **0 violation
CSP**). **Limite de test connue** : le rejet CSRF (419) n'est **pas** testable en
PHPUnit — le framework désactive `ValidateCsrfToken` en environnement `testing`.
Il est prouvé par la bisection du Lot 9a et par les parcours E2E navigateur (qui
exécutent le cycle complet).

**Écart trouvé.** Aucun.

### Configuration de production

**Défenses en place.**
- `APP_DEBUG=false` (fixé dans l'exemple) : un vrai 500 renvoie
  `{"message":"Server Error"}` — ni trace, ni chemin, ni SQL (prouvé Lot 9b).
- Secrets (`/.env.production`, `/backend/.env.production`, certificats)
  **gitignorés** ; `backend/.dockerignore` exclut `.env*`, `tests/`, `.git/` de
  l'image.
- Pièces hors webroot (ADR-11) ; `location ~ /\.` et `location ^~ /storage/` → 404.
- **Journal d'audit append-only** garanti par un trigger PostgreSQL
  (`BEFORE UPDATE OR DELETE` + `BEFORE TRUNCATE`) — indépendant du rôle SQL, donc
  résistant même à un accès direct à la base ou une future route mal écrite.

**Écart trouvé — n°3 (Faible).** Le trigger d'immuabilité **n'avait aucun test**
depuis le Lot 1 : un refactor de migration aurait pu le retirer en silence.
**Corrigé** : `Security/JournalAuditImmuableTest` prouve qu'`INSERT` passe et que
`UPDATE` / `DELETE` / `TRUNCATE` — au Query Builder **comme** via le modèle
Eloquent — lèvent une exception PostgreSQL portant « append-only ».

**Écart trouvé — n°4 (Faible).** Les en-têtes de réponse révélaient les versions
exactes : `X-Powered-By: PHP/8.3.x` et `Server: nginx/1.x` — une aide au
*fingerprinting* pour un scanner. **Corrigé** : `expose_php = Off`
(`docker/php/casa.ini`), `server_tokens off` (`casa.prod.conf` + `default.conf`).
Vérifié à chaud : la réponse porte désormais `Server: nginx` (sans version) et
**aucun** `X-Powered-By`.

**Tests qui le prouvent.** `Security/ConfigDurcieTest` (exemples d'env durcis,
`expose_php = Off`, `server_tokens off`, secrets gitignorés) — rejoué en CI sur
le checkout complet + un `grep` dédié dans le job `prod-config`.

### Affectation en masse (mass assignment)

**Défenses en place.**
- Les modèles à colonnes internes ont un `$fillable` **restrictif** :
  `Candidature` exclut `statut_interne`, `statut_eligibilite_interne`,
  `dossier_verrouille*`, `evaluateur_id`, `numero_dossier`.
- Les contrôleurs construisent **toujours** leurs payloads **clé par clé**
  (`Model::create(['role' => 'candidat', …])`) ou via des méthodes **curées**
  (`VerifierDossierRequest::champsVerification()` = `array_intersect_key` avec un
  allowlist). **Jamais** `->fill($request->all())`.
- Les `FormRequest` marquent en `prohibited` (→ 422 explicite) ce qui ne doit
  jamais transiter : `RegisterRequest` bloque `role`, nationalité, diplôme ;
  `MettreAJourProfilRequest` bloque `email`, `residence_ci`, nationalité, diplôme.
- Les champs calculés serveur (`statut_eligibilite_interne` après vérification,
  `derniere_connexion_le`, `cgu_acceptees_le`) sont posés par `forceFill(...)->saveQuietly()`
  ou explicitement, jamais depuis le corps de requête.

**Test qui le prouve.** `Security/MassAssignmentTest` — tente d'injecter `role`,
`actif`, `id`, `statut_interne`, `statut_eligibilite_interne`, `dossier_verrouille`,
`evaluateur_id`, `numero_dossier`, `cgu_acceptees_le` via `/register`,
`/candidatures`, `/candidat/profil` et `/verification`. Résultat : soit **422**
(champ `prohibited`), soit **silencieusement ignoré** — la valeur en base est
toujours celle décidée par le serveur.

**Écart trouvé.** Aucun. **Note (R-mineur)** : `Candidat.$fillable` contient
`residence_ci` (nécessaire à `RegisterController`) ; la vraie barrière contre son
édition ultérieure est le `prohibited` de `MettreAJourProfilRequest`, testé
ci-dessus. Pas de changement de modèle.

---

## Points résiduels (acceptés / tracés)

| Réf | Sujet | Risque | Décision |
|---|---|---|---|
| **R1** | Sur une route à identifiant, un rôle interdit reçoit 404 (id inexistant) ou 403 (id réel) : oracle théorique « la ressource existe ». | **Très faible** | **Accepté.** Les identifiants sont des UUID de 122 bits aléatoires : l'énumération est infaisable. Rendre le contrôle de rôle antérieur au route-binding serait un changement de priorité de middleware global, disproportionné. |
| **R2** | Pas de contrôle « mot de passe déjà compromis » (`Password::uncompromised()` / Have I Been Pwned). | **Faible** | **Tracé** dans `POINTS-OUVERTS.md` — écarté en v1 (appel réseau sur le chemin d'inscription). Une ligne à activer quand souhaité. |
| **R3** | Pas de vérification d'e-mail à l'inscription (lien / code). | **Faible** | **Tracé** — aucune infrastructure d'envoi d'e-mail dans le projet (`MAIL_MAILER=log`). |
| **R4** | Réponses 429 : le frontend n'affiche un message dédié que sur l'écran de connexion. | **Négligeable** | `apiClient` normalise déjà le 429 en `ApiError('rate_limited')` ; les autres écrans montrent un message d'erreur générique. Amélioration UX possible, pas un défaut de sécurité. |
| **R5** | `throttle:register` est keyé par IP : derrière le reverse-proxy, l'IP client réelle est bien résolue (`trustProxies`, Lot 9b) ; en dev sans en-tête `X-Forwarded-For`, toutes les inscriptions partagent un compteur. | **Négligeable** | Comportement correct en production. |

---

## Inventaire des tests de sécurité

| Fichier | Prouve |
|---|---|
| `backend/tests/Feature/AuthTest.php` | Auth générique, rate-limit login, session, pas de hash exposé |
| `backend/tests/Feature/RoleAccessTest.php` | Matrice de rôle (routes de démonstration) |
| `backend/tests/Feature/Security/MatriceAutorisationTest.php` | **Matrice rôle × route exhaustive** (403/404, 401 invité) + 403 exact sur les actes sensibles |
| `backend/tests/Feature/Security/RateLimitingTest.php` | Les 4 limiteurs existent ; l'usage normal ne les touche pas |
| `backend/tests/Feature/Security/MassAssignmentTest.php` | Aucun champ interne pilotable par requête |
| `backend/tests/Feature/Security/JournalAuditImmuableTest.php` | UPDATE/DELETE/TRUNCATE du journal d'audit rejetés |
| `backend/tests/Unit/Security/PasDeSqlBrutTest.php` | Aucune requête SQL brute sous `app/` |
| `backend/tests/Unit/Security/ConfigDurcieTest.php` | `.env` de prod durcis, `expose_php`/`server_tokens` off, CORS ≠ `*`, 6 en-têtes |
| `backend/tests/Feature/Candidat/SecuriteUploadTest.php` | Upload : MIME par contenu, hors-webroot, nom serveur, taille, traversée |
| `backend/tests/Feature/Candidat/Isolation*Test.php`, `Evaluateur/Isolation*`, `*/NonFuite*` | Isolation propriétaire (404), non-fuite vers le candidat |
| `frontend/src/pages/auth/__tests__/noDemoCreds.test.js` | Aucun identifiant de démonstration dans `src/` |
| `.github/workflows/ci.yml` | `grep` du `dist/` construit + durcissement de configuration |

**Non-régression.** L'ajout des limiteurs et des tests de sécurité ne casse
aucun test existant :

| Suite | Avant (Lot 9c) | Après (Lot 10) |
|---|---|---|
| `php artisan test` | 310 | **337** (+27 sécurité ; 4 `skipped` = contrôles de fichiers de config rejoués en CI sur le checkout complet) |
| `vitest` | 321 | **435** (+114 : `noDemoCreds` scanne 113 fichiers, +1 LoginPage 429) |
| `npm run e2e` (par-espace) | 64 | 64 |
| `npm run e2e:integration` | 9 | 9 |

Le filet de débit a été spécifiquement vérifié contre l'usage légitime :
`RateLimitingTest::test_un_usage_normal_du_wizard_ne_touche_jamais_la_limite`
(40 sauvegardes d'affilée sans 429) et le parcours E2E d'intégration complet
(inscription → 10 étapes → 6 uploads → soumission → évaluation → publication →
**retenu**) restent verts.
