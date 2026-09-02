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

**Implémentation (Lot 4c — volet Entretien).** Même schéma que le 4b. `note_sous_critere_entretien` = sous-notes brutes (12 — cf. D-4c-4), mutables tant que `entretien.statut != 'valide'` ; `GET .../entretien` renvoie un **aperçu** recalculé sur la grille active. À la validation (`POST .../entretien/validation`) : `entretien.statut = 'valide'`, `score_total` (/35) + `grille_id` figés, les **12** `note_sous_critere_entretien` figées (0 pour un sous-critère non renseigné, ou tous à 0 si `presence = 'absent'`), ligne `journal_audit` « Validation d'entretien » (module « Entretien »). Après : `PUT` / `POST .../validation` → **409** ; `GET` → snapshot figé, jamais recalculé (grille v2 activée ⇒ score inchangé — test dédié). Précondition : `candidature.dossier_verrouille = true` (le dossier /65 doit être validé) — **409** sinon. **D-4c-1** : le verrouillage entretien vit sur `entretien.statut = 'valide'` (pas de colonne dédiée sur `candidature`) ; `candidature.statut_interne` reste `evalue` (l'avancement fin sur la table spécialisée, comme la vérification au 4a ; `rankCandidatsParFiliere` de `scoring.js` filtre sur `entretien.statut`). **D-4c-2** : la planification portée par `PUT .../entretien` (date / heure / lieu) est *minimale* — la gestion complète (convocations, calendrier, replanification) est un lot administration (la maquette : « Phase C »). **D-4c-3** : `CHECK (lieu IN ('Le Plateau','2 Plateaux Vallons'))` ajouté sur `entretien` (le MLD ne le citait qu'en commentaire). **D-4c-4** : le volet Entretien compte **12** sous-critères (PRES/REL/EO/MOE × 3), pas « 10 » comme l'écrivaient le cahier des charges du lot et un commentaire du MLD — la source de vérité est `scoring.js` (`CASA_GRILLE`) ; Σ des maxima = 8+10+8+9 = 35. **Divergence de vocabulaire signalée** : les libellés de `entretien.lieu` (`Le Plateau`, `2 Plateaux Vallons`) désignent les deux mêmes sites que les items d'éligibilité DI.04 / DI.05 (`acces_plateau` / `acces_deux_plateaux_vallons`, formulés « au Plateau » / « aux 2 Plateaux Vallons ») — types différents (énum texte vs booléens), aucun couplage technique.

**Implémentation (Lot 4b — volet Dossier).** Tant qu'une évaluation n'est pas validée : **aucune ligne `evaluation_dossier`** ; `GET .../evaluation` renvoie un **aperçu** recalculé à la volée par `ServiceScoring` sur la grille active (`source: "apercu"`). À la validation (`POST .../evaluation/validation`) : `evaluation_dossier` (`score_total` /65, `valide=true`, `valide_par`, `valide_le`) + les **6** lignes `score_rubrique_dossier` + `grille_id` de la grille alors active, dans une transaction ; puis `candidature.dossier_verrouille=true`, `dossier_verrouille_par/le`, `statut_interne='evalue'`, `date_evaluation`, et une ligne `journal_audit` « Validation d'évaluation ». Après verrouillage : `GET .../evaluation` renvoie le **snapshot** figé (`source: "snapshot"`), jamais un recalcul — même si une grille v2 est activée ensuite ; `PUT .../evaluation` et `POST .../evaluation/validation` répondent **409** ; `PUT .../verification` (Lot 4a) aussi (garde `statut_interne === 'en_instruction'`). La ré-ouverture est réservée à la correction exceptionnelle admin (lot ultérieur). **D-4b-1** : `score_rubrique_dossier.score_obtenu` élargi de `numeric(4,2)` à `numeric(6,4)` — `scoring.js` produit des décimales périodiques pour `experience` / `langues` (ex. 6/9×10 = 6,6667) ; `score_total` reste `numeric(4,1)`, arrondi comme `scoring.js`.

## ADR-05 — Réponses brutes et sous-notes persistées séparément et intégralement

**Contexte.** Exigence explicite : le score par rubrique seul ne suffit pas pour permettre un recalcul serveur ou un audit ancienne/nouvelle valeur.
**Décision.** `reponse_formulaire` (1-1 candidature) porte **tous** les champs bruts du formulaire (SC.\*, SE.\*, DI.\*, langues, informatique). `note_sous_critere_entretien` porte les 12 sous-notes d'entretien individuellement (cf. D-4c-4), séparément du score agrégé stocké sur `entretien.score_total`. Ces deux tables sont🔴 mais restent la source que `ServiceScoring` relit pour tout recalcul (correction exceptionnelle notamment).
**Conséquence.** Une correction exceptionnelle peut modifier un champ précis (ex. `di02_contraintes`) et ne recalculer que ce qui en découle, avec une trace exacte "ancienne valeur → nouvelle valeur" au niveau du champ, pas seulement du score agrégé.

## ADR-06 — Élimination : logique serveur versionnée, pas de moteur de règles générique

**Contexte.** Le barème doit être en base (règle 6), mais la logique d'élimination (bornes d'âge, groupe OR sur les 2 sites, seuil de français...) est un algorithme, pas une simple donnée.
**Décision.** `ServiceEligibilite` est codé et testé côté serveur (versionné par le déploiement de code, pas par une table de règles génériques). Chaque candidature trace les critères **effectivement déclenchés** dans `critere_eliminatoire_declenche` (miroir de `motifsElimination` de la maquette), avec un champ `origine` distinguant ce qui est connu dès la soumission candidat de ce qui n'est connu qu'après vérification évaluateur (nationalité, diplôme).
**Conséquence.** Modifier une règle d'élimination (ex. changer le seuil de français) nécessite un déploiement de code (revue, tests), pas juste une modification en base — choix assumé pour la fiabilité, au prix d'un peu moins de souplesse admin sur ce point précis.

**Implémentation (Lot 4c — scoring entretien).** `ServiceScoring::calculerEntretien` — portage fidèle de `scoring.js` `computeEntretienScore()` : chaque sous-note bornée à `[0, sous_critere_entretien.max_points]` (**lu en base**), somme par rubrique (pas de plafond rubrique séparé), somme des rubriques plafonnée au `max_points` du volet, arrondie à une décimale. **Aucune constante algorithmique** (pas de rééchelonnage). Un sous-critère non renseigné vaut **0** (`rNotes[sc.code] ?? 0`). `points_attribues` reste `numeric(3,1)` (demi-points admis) — pas de perte de précision, `score_total` `numeric(4,1)` exact.

**Implémentation (Lot 4b — scoring).** `App\Domain\Scoring\ServiceScoring` (`VERSION = 1`) applique la même règle qu'`ServiceEligibilite` : portage **fidèle** de `scoring.js` `computeScores()` (volet Dossier /65), **barème lu en base** sur la grille passée en argument (poids de rubrique, points d'option, max d'item) — aucune valeur de barème en dur. Seules les **constantes algorithmiques** de `scoring.js` vivent dans le code : domaines d'expérience reconnus, `moisApprox` (3/9/15) et seuils de durée (12, 6), échelle des niveaux langue (0-3), échelle des étoiles (0-5), et la table `item.code → colonne reponse_formulaire`. Le rééchelonnage langues /9→/10 est algorithmique (le dénominateur 9 = somme des `item.max_points` LANG.FR + LANG.INFO). Ce que l'évaluateur saisit et qui entre dans le calcul : `reponse_formulaire.mo04_note_etoiles` (Lot 4b) et `verification_dossier.diplome_verifie` (Lot 4a, pour SC.04). **D-4b-2** : l'évaluateur ne réajuste PAS les autres réponses candidat pendant la notation (la maquette `evaluation.html` le permet) — corriger une déclaration candidat passe par la correction exceptionnelle admin (motif obligatoire, tracée), lot ultérieur. **D-4b-3** : la validation ne relance PAS `ServiceEligibilite` (la maquette le fait) — ses entrées (réponses candidat, vérification 4a) n'ont pas changé depuis les Lots 3c / 4a, un re-run serait un no-op ; `statut_eligibilite_interne` n'est pas touché par 4b. Un dossier `non_eligible` **peut** être noté (le score est un fait ; le classement filtrera).

**Implémentation (Lot 3c).** `App\Domain\Eligibilite\ServiceEligibilite` (`VERSION = 1`), portage fidèle de `scoring.js` : `evaluerSoumission()` ⇔ `checkCriteresEliminatoires` (10 critères connus à la soumission, `origine='soumission_candidat'`), `evaluerVerificationEvaluateur()` ⇔ `checkCriteresEliminatoiresEvaluateur` (SC.04 CEPE, nationalité — `origine='verification_evaluateur'`, **non appelé** au Lot 3c faute de `verification_dossier`). Âge calculé à `campagne.date_ouverture` (référence fixe pour la cohorte). Chaque critère est inséré individuellement dans `critere_eliminatoire_declenche`. La séparation complétude / éligibilité est stricte : `ValidateurCompletude` (dicible, 422 détaillé) ne lit aucun critère, `ServiceEligibilite` ne s'exécute qu'après une complétude OK, et **son résultat n'apparaît dans aucune réponse candidat** (règle reine, séquence a).

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

**Implémentation (Lot 5a — classement & décisions internes).** `App\Domain\Classement\ServiceClassement` (`VERSION = 1`), portage fidèle de `scoring.js` (`computeScoreFinal` + `rankCandidatsParFiliere`) et de `classement.html` (`computeRanking`). **Quotas lus en base** (`campagne_filiere.quota`) ; **score final /100 = somme des snapshots figés** `evaluation_dossier.score_total` + `entretien.score_total` (ADR-04), plafonné à 100, arrondi 1 décimale — **pas** un recalcul des volets (la maquette recalcule via `computeScores`/`computeEntretienScore` faute de snapshot). Inclusion : `dossier_verrouille = true` ET `entretien.statut = 'valide'`. Tri : score final, puis départage **ordre exact scoring.js** — mixité (F) > vulnérabilité NEET (`vulnerabiliteScore`) > expérience secteur hôtellerie-restauration > motivation (`mo04`) — **puis D-5a-1**. Décision : `rang ≤ quota` → `retenu` ; `≤ quota + 8` → `liste_attente` ; sinon `non_retenu`.

- **D-5a-1** : départage FINAL déterministe ajouté (`date_soumission` croissant, puis `candidature.id`). `scoring.js` s'appuie sur la stabilité du tri JS (ordre d'insertion) en cas d'égalité sur les 5 critères ; on rend le résultat reproductible.
- **D-5a-2** : `decision_candidature` (`rang` 🔴, `decision` 🟡, `motif_*`) est **persistée dès le Lot 5a** (calcul du classement), alors que la maquette ne l'écrit qu'à la publication. Aucune `publication` n'est créée ici ⇒ `StatutPublicResolver` inchangé, le candidat ne voit toujours que `en_cours_de_traitement`. `POST /api/admin/campagnes/{c}/classement` est **idempotent** tant qu'aucune `publication` n'existe (409 sinon) : il upsert `rang`/`decision`, supprime les décisions devenues non classables, **préserve** `motif_interne`/`motif_communicable` (gérés par `PUT .../decision/motifs`, admin strict).
- **D-5a-3** : `TAILLE_LISTE_ATTENTE = 8` est une constante de `ServiceClassement` (comme `moisApprox`/`pointsParEtoile`). **Point ouvert** : contrairement aux constantes de calcul, c'est un paramètre métier qui pourrait devenir un attribut de `campagne_filiere` si un ajustement par campagne se confirme.
- **D-5a-4** : un candidat **`non_eligible`** dont le dossier ET l'entretien sont validés reçoit une décision **explicite** `non_retenu` + `motif_interne = 'non éligible'` (🔴, système), `rang = NULL` (migration : `decision_candidature.rang` rendu nullable). Écart assumé à la maquette (qui l'exclut du classement sans ligne) pour que **tout** candidat ait un résultat déterminé au Lot 5b. Règle reine préservée : après publication, ce candidat verra **exactement** le même message qu'un non-retenu ordinaire — il n'apprend jamais que la cause était l'inéligibilité (`motif_interne` reste 🔴). Non-éligible ≠ non-retenu en interne, convergents vers le même message candidat.
- Rôles : `POST` classement + `GET` classement + `PUT` motifs = **administrateur strict** (le classement global expose les scores/rangs de tous les candidats, toutes filières ; un évaluateur n'a de légitimité que sur ses affectations — cf. classement.html côté admin). Un besoin borné « évaluateur voit sa filière » serait ajouté précisément si nécessaire.

## ADR-10 — Rôles : `utilisateur` + spécialisation `candidat` / `membre_equipe`, admin ⊇ évaluateur

**Contexte.** Champs très différents entre candidat (état civil, réponses formulaire...) et équipe projet (poste...) ; comportement observé dans la maquette où un compte admin peut effectuer toutes les actions évaluateur, plus les siennes propres.
**Décision.** `utilisateur` porte l'identité + le rôle ; `candidat` et `membre_equipe` sont deux sous-types exclusifs (CIF, cf. `docs/mcd.md`). Les policies Laravel autorisent `evaluateur` ET `administrateur` sur les endpoints d'évaluation/entretien ; `correction exceptionnelle` et les endpoints d'administration restent strictement réservés à `administrateur`.
**Conséquence.** Pas de duplication de logique de permission entre "évaluateur" et "administrateur agissant comme évaluateur" — une seule policy par action, avec un rôle autorisé à plusieurs valeurs quand c'est le cas.

**Portée d'accès (Lot 4a).** Sur l'espace évaluateur : un **évaluateur** n'accède qu'aux candidatures où `candidature.evaluateur_id = son membre_equipe.id` (ses affectations) ; un **administrateur** accède à **toutes** les candidatures instructibles (admin ⊇ évaluateur, conforme à `candidatures.html` admin de la maquette). Un dossier affecté à un autre évaluateur → **404** (`Response::denyAsNotFound`, cohérence zéro-fuite avec l'isolation candidat). Les Resources évaluateur (`App\Http\Resources\Evaluateur\*`) sont **distinctes** des Resources candidat et jamais réutilisées en croisé : l'évaluateur voit la zone 🔴 du dossier qu'il instruit (`statut_interne`, `statut_eligibilite_interne`, `verification_dossier`, critères éliminatoires), le candidat n'en voit **rien** (garanti par `StatutPublicResolver` + liste blanche `CandidatureCandidatResource`, testé sur le vrai chemin).

## ADR-11 — Pièces justificatives stockées hors webroot

**Contexte.** Documents personnels (CNI, diplôme...) — ne doivent jamais être accessibles par une URL publique directe.
**Décision.** `piece_justificative.chemin_stockage` pointe vers un espace de stockage Laravel non exposé par nginx (`storage/app/private/documents`, disque `documents`, volume Docker dédié `documents_data`, cf. `docker-compose.yml`). Tout accès passe par une route applicative authentifiée et autorisée (policy : le candidat propriétaire, ou un membre équipe affecté/admin), jamais par un lien statique.
**Conséquence.** Un peu de latence supplémentaire (le fichier transite par PHP au lieu d'être servi directement par nginx) en échange d'un contrôle d'accès réel et audité.

**Durcissement (Lot 3b).**
- Le disque Laravel `local` (`storage/app/private`) est passé en `'serve' => false` : la route `GET|PUT /storage/{path}` que Laravel enregistre sinon (accès/upload par URL signée) **n'existe plus** (`php artisan route:list` ne montre aucune route `storage`). Le disque dédié `documents` a lui aussi `serve => false`.
- Le **nom de stockage est 100 % serveur** (`{candidature_id}/{uuid}.{ext}`, `ext` dérivée du MIME détecté par contenu) — jamais construit à partir du nom ou de l'extension client (pas de path traversal, pas de collision). `nom_original` est conservé assaini, pour l'affichage seul.
- Validation d'upload : `mimetypes` (MIME réel détecté par `finfo`, pas le `Content-Type` déclaré) + `extensions` (garde-fou) + `max` 10 Mo. Formats : PDF, JPEG, PNG.
- Téléchargement en **streaming** depuis le disque privé (`Storage::download`), toujours `Content-Disposition: attachment` + `X-Content-Type-Options: nosniff` (jamais `inline` : un PDF/image valide peut porter une charge active).
- `piece_justificative.type_mime` (colonne ajoutée au Lot 3b, cf. `docs/mld.md`) mémorise le MIME détecté pour servir le bon `Content-Type` sans re-scan.

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

**Dépendance signalée (D-4a-1).** Aucun lot livré ne produit d'endpoint d'**affectation** d'un dossier à un évaluateur (`candidature.evaluateur_id`). Le Lot 4a consomme cette colonne en lecture mais ne l'écrit pas : le contexte de test/démo est posé par le trait `CreeContexteEvaluation` (tests) et le seeder explicite `DemoEvaluationSeeder` (smoke), qui fixent `evaluateur_id` + `statut_interne='en_instruction'`. Un **lot administration** devra fournir l'endpoint d'affectation (avec ligne `journal_audit`, action « Affectation »). Convention retenue en attendant : un dossier est réputé « en instruction » dès son affectation.

**Point ouvert à porter au lot inscription (D-3c-1).** Le contrôle d'éligibilité `residence_ci` (résider en Côte d'Ivoire) n'est présent dans `scoring.js` que dans `checkEligibiliteInitiale` (§4.2, inscription), **pas** dans `checkCriteresEliminatoires` (§4.9, soumission). Le Lot 3c porte fidèlement `checkCriteresEliminatoires` : `residence_ci = false` **n'élimine donc pas à la soumission** aujourd'hui. Ce contrôle devra être effectué à l'inscription (ou, à défaut, par l'évaluateur avec `verification_dossier`). À ne pas perdre.

---

## Points laissés ouverts pour un lot ultérieur (non traités ici)

- **Lot inscription / profil candidat** (self-service, état civil, choix de filière) — cf. ADR-13.
- Règle « 1 expérience = 1 justificatif » : validée **à la soumission** (Lot 3c), pas au niveau colonne (`experience_professionnelle.piece_justificative_id` rendu nullable au Lot 3a, cf. `docs/mld.md`).

- **Endpoint d'affectation** d'un dossier à un évaluateur (`candidature.evaluateur_id`) — cf. D-4a-1 (lot administration).
- **Correction exceptionnelle admin** d'une évaluation verrouillée (motif obligatoire, nouveau snapshot tracé) — cf. D-4b-2 / ADR-04.
- **Score final /100** (`computeScoreFinal` = dossier /65 + entretien /35) et **classement par filière** (`rankCandidatsParFiliere` : départage mixité > vulnérabilité > expérience secteur > motivation) — lot ultérieur.
- Détail des policies Laravel par endpoint (matrice complète rôle × action).
- Stratégie de rate limiting / anti-bruteforce sur l'authentification.
- Politique de rétention des pièces justificatives après clôture d'une campagne.
- Notifications réelles (email/SMS) — la maquette les simule uniquement.
- Export Excel/PDF réel des rapports (simulé dans la maquette).
