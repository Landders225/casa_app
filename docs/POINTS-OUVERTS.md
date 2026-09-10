# Points ouverts — inventaire vivant

> **Rôle de ce fichier.** Tout ce qui a été **consciemment reporté** au fil des
> ~40 lots, réuni au même endroit, avec un statut et un pointeur vers la trace
> détaillée. Il est **vivant** : on le met à jour quand un point est traité ou
> qu'un nouveau apparaît. Le **journal figé** des décisions reste `docs/ADR.md`
> (on n'y réécrit pas l'histoire) ; ici on tient le **présent**.
>
> Dernière revue : **Lot 11b** (2026-09-10) — écran admin « Équipe » (création,
> (dés)activation, réinitialisation de mot de passe) + middleware `EnsureUserActif`
> (ADR-29). Voir aussi la revue de sécurité **Lot 10**
> ([`docs/AUDIT-SECURITE.md`](AUDIT-SECURITE.md)).

## Légende de statut

| Statut | Sens |
|---|---|
| 🔴 Bloquant prod | à traiter avant une vraie mise en service auprès de candidats réels |
| 🟠 Ouvert | fonctionnalité ou durcissement attendus, non bloquants pour une première prod pilote |
| 🟡 Décidé — opt-in | tranché, volontairement non activé par défaut ; activation documentée |
| 🟢 Traité | résolu ; ligne gardée pour l'historique, avec le lot de résolution |

---

## Institutionnel / contenu

| Sujet | Statut | Pourquoi ouvert | Où c'est tracé | Prérequis / effort |
|---|---|---|---|---|
| **Relecture des textes candidats par les partenaires** (FAQ publiques évaluation & égalité de score ; textes de résultat / non-retenue / clôture ; libellés de la vitrine) | 🔴 Bloquant prod | Rédaction faite côté dev pour ne rien révéler de la grille (ADR-02) ; la formulation institutionnelle doit être validée par CCI-CI / FADV / AICS | ADR sur 8b-1 et 8b-3 (README, section Avancement) ; composants `pages/public/*`, `pages/candidat/MaCandidature.jsx` | Revue partenaires, puis ajustement de chaînes uniquement |

## Fonctionnalités candidat / inscription

| Sujet | Statut | Pourquoi ouvert | Où c'est tracé | Prérequis / effort |
|---|---|---|---|---|
| **Vérification d'e-mail à l'inscription** (lien ou code) | 🟠 Ouvert | Aucune infra d'envoi d'e-mail dans le projet (`MAIL_MAILER=log`) | ADR — Points ouverts (D-7-2) | Choix d'un fournisseur SMTP + file d'attente + écran de confirmation |
| **Changement d'adresse e-mail** (identifiant de connexion) | 🟠 Ouvert | Flux dédié (ré-authentification + confirmation) non couvert au Lot 7 ; `PATCH /candidat/profil` interdit `email` (ADR-07) | ADR — Points ouverts (D-7-2) | Dépend de la vérification d'e-mail ci-dessus |
| **Durcissement mot de passe — `Password::uncompromised()` (HIBP)** | 🟠 Ouvert | Écarté en v1 : appel réseau (api.pwnedpasswords.com) sur le chemin d'inscription | ADR — Points ouverts ; **AUDIT-SECURITE.md R2** | Une ligne dans `RegisterRequest` + tolérance à l'indispo du service |
| ~~Inscription / profil candidat self-service~~ | 🟢 Traité (Lot 7) | — | ADR-16 | — |
| ~~Éligibilité initiale (tranche d'âge, résidence CI) à l'inscription~~ | 🟢 Traité (Lot 7) | `ServiceEligibiliteInitiale` (ferme D-3c-1) | ADR-16 | — |

## Espace administrateur

| Sujet | Statut | Pourquoi ouvert | Où c'est tracé | Prérequis / effort |
|---|---|---|---|---|
| **Création / édition de campagne et de filière** (dates, quotas, nom, description) | 🟠 Ouvert | Aucun endpoint d'écriture ; en prod ça se fait par `tinker` / SQL (documenté `DEPLOIEMENT.md` § 11.9). Le garde-fou « une seule campagne ouverte » est déjà appliqué | ADR — Points ouverts (**D-6a-2**) ; ADR sur 8d-1 | Endpoints CRUD + écrans + validations (fenêtres de dates, quotas ≥ retenus déjà décidés) |
| **Correction exceptionnelle des auto-déclarations candidat** (SC/SE/DI, langues, expériences) | 🟠 Ouvert | Le **backend l'accepte déjà** intégralement (`CorrigerDossierRequest::reponses()`) ; l'UI (Lot 8d-3) ne construit que nationalité / SC.04 diplôme / MO.04 étoiles / commentaire | **ADR-25** (point ouvert explicite) ; `CorrectionDossierModal.jsx` | UI de formulaire pilotée par la structure de la grille — pas de backend à faire |
| ~~**Export réel des rapports** (Excel / PDF)~~ → voir « Écran Rapports & statistiques » dans **Comptes & équipe** ci-dessous | — | — | — | — |

## Comptes & équipe

Un onglet de la navigation admin (`Rapports`) reste **affiché mais inerte**
(`navConfig.js` : fidélité maquette, `is-inert`). Les onglets `Évaluateurs` et
`Utilisateurs` de la maquette ont été **remplacés par un seul écran « Équipe »**
au Lot 11b (D-11b-1).

| Sujet | Statut | Pourquoi ouvert | Où c'est tracé | Prérequis / effort |
|---|---|---|---|---|
| **Provisionner des comptes équipe en production** (évaluateurs pour constituer le jury, admins supplémentaires) | 🟢 **Traité — CLI (Lot 11a)** | `casa:create-admin` ne créait qu'un administrateur ; `ComptesDemoSeeder` (crée `evaluateur@…`) n'est jamais joué en prod → impossible de constituer un jury | **`casa:create-membre {email} --role=evaluateur\|administrateur`** ; `DEPLOIEMENT.md` § 9 ; `ProvisionnementMembreEquipe` + `CreerMembreEquipeCommand` ; `CreateMembreTest` | — |
| **Écran « Équipe »** (ex-« Utilisateurs » + « Évaluateurs » : liste évaluateurs + admins avec charge, création, activation/désactivation, réinitialisation de mot de passe) | 🟢 **Traité (Lot 11b — ADR-29)** | Onglets inertes ; seule la CLI existait. Un seul écran unifié (candidats hors périmètre, D-11b-1) | **ADR-29** ; `MembreController` + `/api/admin/membres` (4 routes) ; `GestionCompteEquipe` ; `Equipe.jsx` ; `GestionMembresEquipeTest` ; `navConfig.js` (`equipe`) | — |
| **Coupure d'accès immédiate d'un compte désactivé** (session en cours, pas seulement au prochain login) | 🟢 **Traité (Lot 11b — ADR-29)** | `login` refusait déjà un compte inactif, mais une session ouverte survivait jusqu'à `SESSION_LIFETIME` (120 min) | **ADR-29** ; `EnsureUserActif` (groupe `auth:sanctum`) ; `EnsureUserActifTest` | — |
| **Désactiver un compte CANDIDAT** (fraude avérée au-delà de l'« élimination » d'une candidature) | 🟠 Ouvert | Le Lot 11b gère l'équipe (évaluateurs + admins), pas les candidats. Différent d'« éliminer une candidature » (ADR-15) : là c'est le **compte** qu'on bloque. `EnsureUserActif` couvrirait déjà l'exécution — il manque l'acte admin | `MembreController::assertMembreEquipe` (exclut les candidats) ; ADR-15 (élimination ≠ blocage de compte) | Endpoint `role:administrateur` sur un compte candidat + point d'entrée UI (écran de supervision des candidatures ?) + audit |
| **Édition de l'identité d'un membre** (corriger prénom / nom / poste d'un compte d'équipe) | 🟠 Ouvert | Hors des 4 fonctions du Lot 11b (D-11b-4). Aujourd'hui : en base | `MembreController` (pas de route PUT d'identité) ; maquette `utilisateurs.html` (bouton « Gérer ») | 1 route `PATCH` + champs éditables + audit + un formulaire |
| **Écran « Rapports & statistiques »** (restitution CoPil : candidatures par filière, F/H, distribution des scores, top villes ; + export Excel/PDF) | 🟠 Ouvert (**11c**) | Onglet inerte. Jamais construit ; l'ancienne ligne « Export réel des rapports » ne couvrait que l'export, pas l'écran de restitution. Maquette `rapports.html` = 4 graphiques CoPil | `navConfig.js` (`rapports`, `is-inert`) ; maquette `pages/admin/rapports.html` ; ADR sur 8d-2 (dashboard sans Chart.js) | Endpoints d'agrégation `role:administrateur` (jamais de 🔴 individuel), écran avec graphiques, génération de l'export |

## Notifications

| Sujet | Statut | Pourquoi ouvert | Où c'est tracé | Prérequis / effort |
|---|---|---|---|---|
| **Notifications réelles (e-mail / SMS)** — accusé d'inscription, dossier affecté, publication des résultats | 🟠 Ouvert | La maquette les simule ; aucune infra d'envoi. `MAIL_MAILER=log` en prod | ADR — Points ouverts ; ADR-27 (« infra mail hors CASA ») | Fournisseur e-mail + passerelle SMS + file d'attente (`QUEUE_CONNECTION=database` déjà prêt) + templates |

## Sécurité / conformité

| Sujet | Statut | Pourquoi ouvert | Où c'est tracé | Prérequis / effort |
|---|---|---|---|---|
| **HSTS `preload`** | 🟡 Décidé — opt-in | Soumettre le domaine à hstspreload.org est engageant et lent à défaire ; on garde `max-age=1 an + includeSubDomains` par défaut, `preload` activable consciemment une fois la prod stable | **ADR-27** (point ouvert) → **ADR-28** (décision) ; commentaire dans `docker/nginx/casa.prod.conf` | Ajouter ` preload` à l'en-tête + reload nginx + soumission sur hstspreload.org |
| ~~**Matrice complète policies Laravel × endpoints** (rôle × action)~~ | 🟢 Traité (Lot 10) | `Security/MatriceAutorisationTest` introspecte la table de routage et vérifie **chaque** route `role:`-gardée × chaque mauvais rôle (403/404) + invité (401) + 403 exact sur les actes sensibles | **AUDIT-SECURITE.md** § Autorisation | — |
| ~~**Rate limiting / anti-bruteforce** au-delà du login~~ | 🟢 Traité (Lot 10) | 4 limiteurs : `casa-public` 60/min/IP, `casa-api` 120/min/user (filet global), `casa-uploads` 40/min, `casa-candidatures` 12/min. Calibrés pour ne gêner aucun usage légitime (`RateLimitingTest`) | **AUDIT-SECURITE.md** n°2 | — |
| **HSTS `preload`** — voir ci-dessus (opt-in) | 🟡 | — | — | — |
| **Politique de rétention des pièces justificatives** après clôture de campagne | 🟠 Ouvert | Les pièces restent indéfiniment dans `documents_data` ; pas de purge ni de durée légale définie | ADR — Points ouverts ; AUDIT-SECURITE.md | Décision juridique (durée) + commande de purge + sauvegarde préalable |
| **Comptes de démonstration sur la page de connexion** | 🟢 Traité (Lot 10) | Retirés du code ; absence prouvée dans `src/` (`noDemoCreds.test.js`) et dans `dist/` (CI) | **AUDIT-SECURITE.md** n°1 | — |
| **En-têtes révélant les versions PHP/nginx** | 🟢 Traité (Lot 10) | `expose_php = Off`, `server_tokens off` | **AUDIT-SECURITE.md** n°4 | — |
| **Trigger d'immuabilité du journal d'audit non testé** | 🟢 Traité (Lot 10) | `Security/JournalAuditImmuableTest` (UPDATE/DELETE/TRUNCATE rejetés) | **AUDIT-SECURITE.md** n°3 | — |
| **Vérification d'e-mail à l'inscription** | 🟠 Ouvert | — voir « Fonctionnalités candidat » ci-dessus | **AUDIT-SECURITE.md R3** | — |
| **`config:cache` et multi-réplicas** | 🟡 Décidé — mono-nœud | L'entrypoint refait les caches à chaque `up`/`--force-recreate` (ADR-28) : correct pour **un** nœud backend. Plusieurs réplicas derrière une LB demanderaient une orchestration du recreate | ADR-27 (renvoi runbook) → ADR-28 ; `docker/backend/entrypoint.sh` | Orchestrateur (Swarm/K8s) + rolling update ; hors périmètre actuel |
| ~~Cause exacte du `419 CSRF` en enchaînement `curl`~~ | 🟢 Traité (Lot 9a) | Artefact `curl` (décodage bash du token), **pas un bug d'auth** — prouvé par bisection | **ADR-26** ; `scratchpad/csrf419.sh` | — |

## Intégration continue / déploiement

| Sujet | Statut | Pourquoi ouvert | Où c'est tracé | Prérequis / effort |
|---|---|---|---|---|
| **Activer la CI** (`.github/workflows/ci.yml`, `nightly.yml`) | 🟠 Ouvert | Le dépôt n'a **pas de remote**. Les workflows sont écrits et chaque étape a été prouvée en local (Lot 9c) | **ADR-28** ; en-tête de `ci.yml` | `git remote add origin <url>` + `git push` ; GitHub exécute alors les workflows tels quels |
| **E2E d'intégration dans la CI de PR** | 🟡 Décidé — nightly | Trop long (~15 min) pour bloquer chaque PR ; tourne en `nightly.yml` (03:00 UTC) + `workflow_dispatch` | ADR-28 ; `nightly.yml` | — |

## Divers (traçabilité de conception)

| Sujet | Statut | Détail | Où c'est tracé |
|---|---|---|---|
| ~~Résidence CI (`residence_ci`)~~ | 🟢 Traité (Lot 7) | Portée à l'inscription | ADR-16 ; `docs/mld.md` |
| Règle « 1 expérience = 1 justificatif » | 🟢 Traité (Lot 3c) | Validée **à la soumission**, pas au niveau colonne (`experience_professionnelle.piece_justificative_id` nullable) | ADR — Points ouverts ; `docs/mld.md` |
