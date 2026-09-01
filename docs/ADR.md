# CASA — Décisions d'architecture (ADR)

Lot 0. Chaque entrée : contexte → décision → conséquence. Numérotées, jamais renumérotées (une décision annulée est marquée `[REMPLACÉE PAR ADR-xx]`, pas supprimée).

---

## ADR-01 — Authentification : Sanctum SPA (cookies same-origin)

**Contexte.** Frontend React SPA séparé du backend Laravel, mais destiné à être servi depuis le même domaine public via le reverse proxy nginx (règle imposée).
**Décision.** Laravel Sanctum en mode SPA (cookie de session, pas de token Bearer exposé au JS). `SANCTUM_STATEFUL_DOMAINS`/`SESSION_DOMAIN` alignés sur le domaine public unique servi par nginx.
**Conséquence.** Pas de jeton d'API stockable côté client (moins de surface d'attaque XSS/exfiltration) ; toute requête API doit passer par le même domaine que le frontend (contrainte sur le déploiement — un seul point d'entrée nginx, cf. docker-compose.yml). CSRF cookie (`/sanctum/csrf-cookie`) à récupérer avant toute requête mutante — à implémenter au lot d'authentification.

## ADR-02 — Scoring 100 % serveur, jamais dans le bundle ni une réponse API candidat

**Contexte.** Règle non négociable 1. Dans la maquette, `scoring.js` est chargé par **toutes** les pages, y compris candidat (masquage a posteriori par `casaCandidatPourAffichage()` côté client — une dette qu'on ne reproduit pas).
**Décision.** Le barème (`grille`, `volet`, `rubrique`, `item`, `option_item`, `sous_critere_entretien`, `critere_priorite`) et toute la logique de calcul (`ServiceScoring`, `ServiceEligibilite`, `ServiceClassement`) vivent exclusivement côté backend Laravel. Aucun de ces éléments n'est sérialisé dans une réponse API atteignable par le rôle `candidat`, ni présent dans le bundle React.
**Conséquence.** Toute API Resource candidat doit être écrite par liste blanche de champs (jamais `Resource::make($model)` générique sur `candidature`/`evaluation_dossier`) et revue contre la checklist confidentialité de `docs/dictionnaire-donnees.md`. Un test automatisé (lot ultérieur) doit vérifier qu'aucune route accessible à `candidat` ne peut retourner une colonne 🔴.

## ADR-03 — Statut public dérivé exclusivement côté serveur (statut interne ≠ statut affiché)

**Contexte.** Exigence explicite du Lot 0 : ne pas reproduire `casaCandidatPourAffichage()` comme fonction de masquage côté client. `candidature.statut_interne` peut valoir `non_eligible`/`evalue`/etc. avant toute publication ; le candidat ne doit voir qu'un statut neutre jusqu'à publication.
**Décision.** `statut_interne` **n'est jamais stocké en double** sous une forme "publique" (pas de colonne `statut_public` redondante, source de désynchronisation possible). À la place, un composant serveur unique et non contournable — `StatutPublicResolver` (service applicatif, invoqué par une API Resource dédiée `CandidatureCandidatResource`) — calcule le statut affichable à la volée, à partir de `statut_interne` + existence de `publication` sur la campagne :

| `statut_interne` | `publication` existe ? | `statut_public` renvoyé |
|---|---|---|
| brouillon / soumis / en_instruction / non_eligible / evalue | non | `en_cours_de_traitement` (toujours, sans exception) |
| n'importe lequel | oui | `decision_publiee` + `decision_candidature.decision` |

`decision_candidature.rang` et `.motif_interne` ne sont **jamais** lus par ce composant. `.motif_communicable` n'est lu et exposé que dans la branche "publication existe", et seulement s'il est non nul (sinon message générique **fixe côté client**, pas une donnée serveur).
**Conséquence.** Aucun contrôleur candidat n'a le droit d'accéder directement à `candidature.statut_interne` ou `decision_candidature` — seul `StatutPublicResolver` le peut, et il est le seul composant testé pour cette dérivation (un seul endroit à faire évoluer si la règle change). Illustré en détail dans `docs/uml-sequences.md`, séquence (c).

## ADR-04 — Score figé (snapshot) + grille versionnée

**Contexte.** Le barème doit être administrable en base (règle 6), donc modifiable dans le temps. Une évaluation déjà verrouillée ne doit jamais changer de valeur suite à une modification ultérieure de la grille.
**Décision.** `grille` est versionnée (`version`, `actif`) ; `evaluation_dossier`/`entretien` référencent la version exacte utilisée (`grille_id`) et stockent un `score_total` **figé** au moment de la validation, ainsi qu'un détail par rubrique (`score_rubrique_dossier`/`note_sous_critere_entretien`). Le recalcul serveur (`ServiceScoring`) ne s'exécute que pour un dossier **non verrouillé** (brouillon d'évaluation) ou lors d'une correction exceptionnelle explicite (qui produit un nouveau snapshot, tracé à l'audit).
**Conséquence.** Changer la pondération d'une rubrique n'affecte que les évaluations futures. Un audit "que se serait-il passé avec l'ancien barème" reste possible en conservant `grille_id` sur chaque évaluation historique.

## ADR-05 — Réponses brutes et sous-notes persistées séparément et intégralement

**Contexte.** Exigence explicite : le score par rubrique seul ne suffit pas pour permettre un recalcul serveur ou un audit ancienne/nouvelle valeur.
**Décision.** `reponse_formulaire` (1-1 candidature) porte **tous** les champs bruts du formulaire (SC.\*, SE.\*, DI.\*, langues, informatique). `note_sous_critere_entretien` porte les 10 sous-notes d'entretien individuellement, séparément du score agrégé stocké sur `entretien.score_total`. Ces deux tables sont🔴 mais restent la source que `ServiceScoring` relit pour tout recalcul (correction exceptionnelle notamment).
**Conséquence.** Une correction exceptionnelle peut modifier un champ précis (ex. `di02_contraintes`) et ne recalculer que ce qui en découle, avec une trace exacte "ancienne valeur → nouvelle valeur" au niveau du champ, pas seulement du score agrégé.

## ADR-06 — Élimination : logique serveur versionnée, pas de moteur de règles générique

**Contexte.** Le barème doit être en base (règle 6), mais la logique d'élimination (bornes d'âge, groupe OR sur les 2 sites, seuil de français...) est un algorithme, pas une simple donnée.
**Décision.** `ServiceEligibilite` est codé et testé côté serveur (versionné par le déploiement de code, pas par une table de règles génériques). Chaque candidature trace les critères **effectivement déclenchés** dans `critere_eliminatoire_declenche` (miroir de `motifsElimination` de la maquette), avec un champ `origine` distinguant ce qui est connu dès la soumission candidat de ce qui n'est connu qu'après vérification évaluateur (nationalité, diplôme).
**Conséquence.** Modifier une règle d'élimination (ex. changer le seuil de français) nécessite un déploiement de code (revue, tests), pas juste une modification en base — choix assumé pour la fiabilité, au prix d'un peu moins de souplesse admin sur ce point précis.

## ADR-07 — Nationalité et diplôme : uniquement dans `verification_dossier`

**Contexte.** Fidélité au flux réel de la maquette (`inscription.html` : `nationalite: null` à la création) — aucun de ces champs n'est jamais auto-déclaré par le candidat.
**Décision.** Aucune colonne nationalité/diplôme sur `candidat` ou `reponse_formulaire`. Seule `verification_dossier.nationalite_confirmee`/`.diplome_verifie`, saisie par un `membre_equipe`, fait foi.
**Conséquence.** Le formulaire candidat (frontend) n'a structurellement aucun champ à afficher pour ces deux informations — cohérence garantie par le modèle, pas seulement par l'écran.

## ADR-08 — Publication rattachée à la campagne (pas de flag global)

**Contexte.** La maquette utilise un flag applicatif unique (`CasaPublication`), simplification permise par l'absence de campagnes concurrentes dans la démo.
**Décision.** `publication` est 1-1 avec `campagne` (une campagne = au plus une publication). `StatutPublicResolver` (ADR-03) résout la visibilité **par campagne** de la candidature concernée, pas globalement.
**Conséquence.** Plusieurs campagnes peuvent coexister avec des publications indépendantes (ex. Cohorte 1 publiée pendant que Cohorte 2 est encore en cours d'évaluation).

## ADR-09 — Quota porté par `campagne_filiere`, pas par `filiere`

**Contexte.** Idem ADR-08 : la maquette modifie `cqp.quotaParCohorte` comme un attribut permanent de la filière.
**Décision.** Le quota est un attribut de l'association N-N `campagne_filiere`, pas de `filiere` elle-même.
**Conséquence.** Une même filière peut avoir un quota différent d'une cohorte à l'autre sans conflit.

## ADR-10 — Rôles : `utilisateur` + spécialisation `candidat` / `membre_equipe`, admin ⊇ évaluateur

**Contexte.** Champs très différents entre candidat (état civil, réponses formulaire...) et équipe projet (poste...) ; comportement observé dans la maquette où un compte admin peut effectuer toutes les actions évaluateur, plus les siennes propres.
**Décision.** `utilisateur` porte l'identité + le rôle ; `candidat` et `membre_equipe` sont deux sous-types exclusifs (CIF, cf. `docs/mcd.md`). Les policies Laravel autorisent `evaluateur` ET `administrateur` sur les endpoints d'évaluation/entretien ; `correction exceptionnelle` et les endpoints d'administration restent strictement réservés à `administrateur`.
**Conséquence.** Pas de duplication de logique de permission entre "évaluateur" et "administrateur agissant comme évaluateur" — une seule policy par action, avec un rôle autorisé à plusieurs valeurs quand c'est le cas.

## ADR-11 — Pièces justificatives stockées hors webroot

**Contexte.** Documents personnels (CNI, diplôme...) — ne doivent jamais être accessibles par une URL publique directe.
**Décision.** `piece_justificative.chemin_stockage` pointe vers un espace de stockage Laravel non exposé par nginx (`storage/app/private`, volume Docker dédié `documents_data`, cf. `docker-compose.yml`). Tout accès passe par une route applicative authentifiée et autorisée (policy : le candidat propriétaire, ou un membre équipe affecté/admin), jamais par un lien statique.
**Conséquence.** Un peu de latence supplémentaire (le fichier transite par PHP au lieu d'être servi directement par nginx) en échange d'un contrôle d'accès réel et audité.

## ADR-12 — Journal d'audit immuable : application **et** garantie PostgreSQL

**Contexte.** Règle 5 : traçabilité persistante des actions sensibles. L'append-only strictement applicatif (absence de route de modification) protège contre le code métier normal, mais pas contre un accès direct à la base ni contre une future route mal écrite.
**Décision.** `journal_audit` est append-only, garanti à **deux niveaux** :
1. **Applicatif** — aucune route, policy ou contrôleur ne propose de mise à jour ou de suppression sur ce modèle. Chaque action sensible (correction exceptionnelle, remplacement, publication, modification de grille, activation/désactivation de filière, ouverture/clôture de campagne, affectation, élimination manuelle, motif de non-retenue) écrit une ligne, avec motif obligatoire quand la règle métier l'exige.
2. **PostgreSQL (tranché au Lot 1)** — une migration dédiée (`..._add_journal_audit_append_only_trigger`) installe une fonction PL/pgSQL et deux triggers sur `journal_audit` : `BEFORE UPDATE OR DELETE` (par ligne) et `BEFORE TRUNCATE` (par instruction). Toute tentative lève `RAISE EXCEPTION` (`ERRCODE restrict_violation`) et est rejetée. Seul `INSERT` reste permis.

*Trigger plutôt que `REVOKE UPDATE, DELETE`* : le trigger protège indépendamment du rôle SQL utilisé, y compris le propriétaire des tables dont se sert Laravel (qu'un `REVOKE` sur le rôle applicatif n'atteindrait pas).

**Conséquence.** L'immuabilité ne repose plus sur la seule absence de chemin applicatif : un `UPDATE`/`DELETE`/`TRUNCATE` sur `journal_audit` échoue au niveau moteur, quel que soit le point d'entrée (route, `php artisan tinker`, `psql` direct, script de maintenance). Le `DROP TABLE` de `migrate:fresh` n'est pas concerné (DDL de gestion de schéma, pas une altération de ligne) ; un contournement resterait théoriquement possible pour un super-utilisateur PostgreSQL via `SET session_replication_role = replica`, ce qui n'est pas le rôle applicatif et sortirait de tout usage normal. Corollaire opérationnel : une ligne insérée par erreur ne peut pas être « nettoyée » — les tests du trigger s'exécutent donc en transaction annulée (`ROLLBACK`).

## ADR-13 — Pas de parcours d'inscription dédié : la création de candidature le porte temporairement (Lot 3a)

**Contexte.** Dans la maquette, `inscription.html` crée le couple `candidat` + `candidature` : il fixe la filière visée (`filiere_id`), génère le `numero_dossier`, initialise le classement des préférences et met `statut='brouillon'`. Aucun lot livré (0 à 3a) n'a produit d'endpoint d'inscription ; le Lot 2 se limite à l'authentification et à 3 comptes de démonstration seedés (le `candidat` de démo est créé par un seeder).
**Décision.** Au Lot 3a, `POST /api/candidatures` **porte le geste d'inscription** : il prend `filiere_id` en entrée (filière ouverte pour la campagne en cours), génère le `numero_dossier` (`CASA-<année>-<6 chiffres>`), crée la ligne `reponse_formulaire` vide (1-1 strict) et pré-remplit `classement_filiere_preference` (filière visée en rang 1). L'édition des champs d'identité du `candidat` (`prenom`, `nom`, `date_naissance`, `cni`…) **n'est pas** couverte par ce lot.
**Conséquence.** Un **lot inscription / profil réel reste à faire** : création de compte candidat en self-service, saisie/mise à jour de l'état civil, choix initial de la filière. Quand il existera, `POST /api/candidatures` sera réduit à « ouvrir la candidature de la campagne courante » (la filière proviendra du profil / de l'inscription) sans changer le contrat de lecture. En attendant, tester le Lot 3a nécessite un `candidat` pré-existant (seedé ou créé à la main).

---

## Points laissés ouverts pour un lot ultérieur (non traités ici)

- **Lot inscription / profil candidat** (self-service, état civil, choix de filière) — cf. ADR-13.
- Règle « 1 expérience = 1 justificatif » : validée **à la soumission** (Lot 3c), pas au niveau colonne (`experience_professionnelle.piece_justificative_id` rendu nullable au Lot 3a, cf. `docs/mld.md`).

- Détail des policies Laravel par endpoint (matrice complète rôle × action).
- Stratégie de rate limiting / anti-bruteforce sur l'authentification.
- Politique de rétention des pièces justificatives après clôture d'une campagne.
- Notifications réelles (email/SMS) — la maquette les simule uniquement.
- Export Excel/PDF réel des rapports (simulé dans la maquette).
