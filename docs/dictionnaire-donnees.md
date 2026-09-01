# CASA — Dictionnaire de données

Lot 0 — Conception uniquement. Extrait par lecture de la maquette (`App_maquette/assets/js/scoring.js`, `mock-data.js`, `app.js`, écrans HTML) — source de vérité fonctionnelle, non portée telle quelle.

## Légende confidentialité

| Marqueur | Signification |
|---|---|
| 🟢 PUBLIC | Peut apparaître dans une réponse API candidat, sans restriction. |
| 🟡 CANDIDAT-APRÈS-PUBLICATION | Visible du candidat **uniquement** une fois `campagne` publiée, et uniquement via la décision finale — jamais avant. |
| 🔴 CONFIDENTIEL | **Jamais** sérialisé dans une réponse API destinée au rôle `candidat`, à aucun moment, avant ou après publication. Réservé `evaluateur`/`administrateur`. Autorisation vérifiée **côté serveur** (policy Laravel), jamais par un simple masquage de champ côté client. |

Cette légende est reprise en tête de chaque table du MLD (`docs/mld.md`) : chaque colonne y porte explicitement son marqueur. Elle constitue la checklist de confidentialité à appliquer lors de l'écriture des API Resources (Lot ultérieur).

---

## 1. Identité & rôles

### `utilisateur`
Identité de connexion, commune aux 3 rôles (Sanctum SPA).

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| id | uuid | 🟢 | |
| email | string, unique | 🟢 | |
| mot_de_passe_hash | string | 🔴 | Jamais lu, seulement vérifié serveur. |
| role | enum(candidat, evaluateur, administrateur) | 🟢 | |
| actif | bool | 🟢 | Compte suspendu ou non. |
| cree_le, derniere_connexion_le | timestamp | 🟢 | |

### `candidat`
1-1 avec `utilisateur` (role = candidat). Champs déclarés par le candidat à l'inscription/au formulaire — **jamais** nationalité ni diplôme (cf. `verification_dossier`).

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| id | uuid | 🟢 | |
| utilisateur_id | fk utilisateur | 🟢 | |
| prenom, nom | string | 🟢 | |
| sexe | enum(F, H) | 🟢 | Utilisé en interne pour le critère de priorité "mixité" (🔴 l'usage du critère l'est, la valeur brute déclarative ne l'est pas). |
| date_naissance | date | 🟢 | |
| cni | string | 🟢 | Numéro déclaré ; la pièce elle-même est dans `piece_justificative`. |
| telephone | string | 🟢 | |
| ville_residence | string (réf. `ville`) | 🟢 | |
| residence_ci | bool | 🟢 | Déclaré à l'inscription. |
| photo_initiales | string, dérivé | 🟢 | |

### `membre_equipe`
1-1 avec `utilisateur` (role = evaluateur **ou** administrateur). [MAQUETTE confirmé] un compte `administrateur` peut agir comme évaluateur (accès dossier/entretien), en plus de ses actions propres (correction exceptionnelle, publication...).

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| id | uuid | 🟢 (jamais exposé au candidat de toute façon) | |
| utilisateur_id | fk utilisateur | | |
| prenom, nom, poste | string | | Ex. "Chargée d'évaluation", "Coordinatrice projet CASA". |
| initiales | string, dérivé | | |

---

## 2. Filières & campagnes

### `filiere`
Les 5 CQP. Catalogue stable (pas de CRUD libre côté admin dans la maquette — `cqp.html` désactive "Ajouter une filière").

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| id | uuid | 🟢 | |
| code | string, unique | 🟢 | ex. `accueil-reception` |
| nom, description, icone | string | 🟢 | |
| competences | json / table liée | 🟢 | Liste de compétences clés affichées publiquement. |
| actif | bool | 🟢 | Filière ouverte/fermée aux candidatures (affiché "Actuellement fermé" côté public si false). |

### `campagne`
Une cohorte (ex. "Cohorte 1 — 2026").

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| id | uuid | 🟢 | |
| nom | string | 🟢 | |
| statut | enum(brouillon, ouverte, cloturee) | 🟢 | |
| date_ouverture, date_cloture | date | 🟢 | |
| places_totales | int | 🟢 | Objectif global (120), somme indicative des quotas filière. |
| description | text | 🟢 | |

### `campagne_filiere`
[DÉCISION validée] table de liaison portant le quota, pour permettre des quotas différents par campagne (au lieu d'un quota figé sur `filiere`).

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| campagne_id | fk campagne | 🟢 | |
| filiere_id | fk filiere | 🟢 | |
| quota | int | 🟢 | Places disponibles pour cette filière, cette campagne. |

---

## 3. Candidature & réponses au formulaire

### `candidature`
Une candidature = un candidat + une campagne + une filière, à un instant donné. Porte le **statut interne** (cf. `docs/ADR.md`, décision "Statut public dérivé côté serveur").

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| id | uuid | 🟢 (l'id lui-même n'est pas sensible) | |
| candidat_id, campagne_id, filiere_id | fk | 🟢 | |
| numero_dossier | string, unique | 🟢 | ex. `CASA-2026-000123` |
| **statut_interne** | enum(brouillon, soumis, en_instruction, non_eligible, evalue) | 🔴 | **Jamais renvoyé tel quel à un candidat.** cf. dérivation "statut public", ADR. |
| **statut_eligibilite_interne** | enum(non_verifie, eligible, non_eligible) | 🔴 | Idem. |
| date_soumission | timestamp | 🟢 | |
| dossier_verrouille | bool | 🔴 | Détail de workflow interne. |
| dossier_verrouille_le, dossier_verrouille_par | timestamp, fk membre_equipe | 🔴 | |
| evaluateur_id | fk membre_equipe | 🔴 | Affectation interne. |
| commentaire_evaluateur | text | 🔴 | Appréciation qualitative interne. |
| date_evaluation | date | 🔴 | |

### `reponse_formulaire`
1-1 avec `candidature`. **[Exigence explicite]** toutes les réponses brutes du formulaire, persistées intégralement — indispensables au recalcul serveur et à l'audit (ancienne/nouvelle valeur d'une correction). Reflète 1:1 les champs de `scoring.js`/`candidature.html`.

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| candidature_id | fk, 1-1 | 🔴 | |
| sc01_scolarise_actuellement | enum(oui, non) | 🔴 | |
| sc02_derniere_classe | enum(avant_3e, cap, 3e, seconde, 1ere, terminale, bt_bep) | 🔴 | |
| sc03_document_justifiant_niveau | enum(oui, non) | 🔴 | |
| sc05_beneficiaire_formation_actuelle | enum(oui, non) | 🔴 | |
| sc06_deja_beneficie_formation, sc07_filiere_suivie, sc08_mene_a_terme, sc09_motif_non_achevement | mixte | 🔴 | Non notés (informatifs, cf. grille), mais toujours persistés. |
| se01_vit_avec | enum(pere, mere, les_deux, aucun) | 🔴 | |
| se02_orphelin | enum(oui, non) | 🔴 | |
| se03_situation_emploi | enum(sans_emploi, stage, interim, temps_partiel, temps_plein) | 🔴 | |
| se04_source_revenu | enum(parent, conjoint, agr, aucune) | 🔴 | |
| se05_personnes_a_charge | enum(0, 1-2, 3+) | 🔴 | |
| se06_soutien_menage | enum(oui, non) | 🔴 | |
| langue_ecrit, langue_parle, langue_comprehension | int (0-3) | 🔴 | |
| info_word, info_excel, info_internet | int (0-3) | 🔴 | |
| acces_plateau, acces_deux_plateaux_vallons | enum(oui, non) | 🔴 | Logique OR (règle 3), éliminatoire seulement si les deux = non. |
| mo04_lettre_motivation | text | 🔴 | Texte libre du candidat. |
| mo04_note_etoiles | int (0-5), nullable | 🔴 | **Saisie évaluateur**, jamais candidat. |
| di01_disponible_lun_ven | enum(oui, non) | 🔴 | Éliminatoire si non. |
| di02_contraintes | enum(aucune, gerable, bloquante) | 🔴 | |
| di03_engagement_complet | enum(oui, non) | 🔴 | Éliminatoire si non. |

### `experience_professionnelle`
0..n par candidature.

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| id | uuid | 🔴 | |
| candidature_id | fk | 🔴 | |
| domaine | enum(hotellerie, restauration, commerce) | 🔴 | |
| duree_categorie | enum(moins_6, 6_12, plus_12) | 🔴 | |
| piece_justificative_id | fk, **obligatoire** | 🔴 | Règle "1 expérience = 1 justificatif". |

### `classement_filiere_preference`
Ordre de préférence du candidat sur les 5 filières (MO.03 — glisser-déposer / flèches ↑↓ dans la maquette). 🟢 information candidat lui-même, pas confidentielle en soi (ce n'est pas un classement de candidats, mais l'ordre de préférence exprimé par LE candidat).

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| candidature_id | fk | 🟢 | |
| filiere_id | fk | 🟢 | |
| rang | int (1-5) | 🟢 | |

### `type_document` / `piece_justificative`

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| type_document.code | enum(cni, residence, diplome, cv, lettre, photo) | 🟢 | Référentiel. |
| piece_justificative.id | uuid | 🟢 | |
| piece_justificative.candidature_id ou experience_id | fk (l'un ou l'autre) | 🟢 | |
| piece_justificative.type_document_id | fk | 🟢 | |
| piece_justificative.chemin_stockage | string | 🔴 | **Hors webroot** (cf. ADR) — jamais une URL publique directe. |
| piece_justificative.nom_original, taille, depose_le | string/int/timestamp | 🟢 | |

---

## 4. Vérification & évaluation du dossier

### `verification_dossier`
1-1 avec `candidature`. **Seule** source de vérité pour nationalité/diplôme — jamais auto-déclarés (validé).

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| candidature_id | fk, 1-1 | 🔴 | |
| nationalite_confirmee | bool, nullable | 🔴 | Saisi par l'évaluateur depuis la CNI déposée. |
| diplome_verifie | enum(cepe, cap, bepc, bac, bt_bep), nullable | 🔴 | Saisi par l'évaluateur depuis le diplôme/bulletin déposé. `cepe` = éliminatoire (SC.04). |
| verifie_par, verifie_le | fk membre_equipe, timestamp | 🔴 | |

### `evaluation_dossier`
1-1 avec `candidature`. **[Décision validée]** score = snapshot figé + référence de version de grille.

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| candidature_id | fk, 1-1 | 🔴 | |
| grille_id | fk `grille` (version utilisée) | 🔴 | |
| score_total | decimal(4,1) | 🔴 | Snapshot /65, figé à la validation. |
| valide | bool | 🔴 | Verrouillage réel (règle 4). |
| valide_le, valide_par | timestamp, fk membre_equipe | 🔴 | |

### `score_rubrique_dossier`
Snapshot du score **par rubrique** (validé : granularité rubrique, pas item). 6 lignes par évaluation (Scolaire, Socio-éco, Expérience, Langues, Motivation, Disponibilité).

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| evaluation_dossier_id | fk | 🔴 | |
| rubrique_id | fk `rubrique` (barème) | 🔴 | |
| score_obtenu | decimal(4,2) | 🔴 | |

### `critere_eliminatoire_declenche`
Trace, **par candidature**, les critères éliminatoires effectivement déclenchés (miroir de `motifsElimination` côté maquette) — logique d'élimination elle-même codée serveur (validé), pas cette table.

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| id | uuid | 🔴 | |
| candidature_id | fk | 🔴 | |
| code_critere | string | 🔴 | ex. `DI.01`, `acces_sites`, `age_min`. |
| detail | text | 🔴 | ex. "Âge inférieur à 18 ans". |
| origine | enum(soumission_candidat, verification_evaluateur) | 🔴 | Distingue les critères connus dès la soumission de ceux connus seulement après vérification évaluateur (nationalité, diplôme). |
| declenche_le | timestamp | 🔴 | |

---

## 5. Entretien

### `entretien`
1-1 avec `candidature`. N'existe qu'une fois le dossier verrouillé.

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| candidature_id | fk, 1-1 | 🔴 | |
| statut | enum(planifie, realise, valide) | 🔴 | |
| date, heure, lieu | date/time/string | 🔴 | Lieu ∈ {Le Plateau, 2 Plateaux Vallons}. |
| evaluateur_id | fk membre_equipe | 🔴 | |
| presence | enum(present, absent), nullable | 🔴 | Si absent : sous-critères forcés à 0. |
| observation | text | 🔴 | |
| grille_id | fk (version utilisée) | 🔴 | |
| score_total | decimal(4,1) | 🔴 | Snapshot /35. |
| valide_le, valide_par | timestamp, fk membre_equipe | 🔴 | |

### `note_sous_critere_entretien`
**[Exigence explicite]** sous-notes persistées intégralement et séparément (10 lignes : PRES.01-03, REL.01-03, EO.01-03, MOE.01-03).

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| entretien_id | fk | 🔴 | |
| sous_critere_id | fk `sous_critere_entretien` (barème) | 🔴 | |
| points_attribues | decimal(3,1) | 🔴 | |

---

## 6. Barème (en base, versionné — règle 6)

### `grille`
Une version du barème complet.

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| id | uuid | 🔴 | |
| version | int | 🔴 | |
| label | string | 🔴 | |
| date_effet | date | 🔴 | |
| actif | bool | 🔴 | Une seule version active à la fois pour les nouvelles évaluations. |

### `volet` (Dossier /65, Entretien /35)
| Attribut | 🔒 |
|---|---|
| id, grille_id, code(dossier\|entretien), label, max_points | 🔴 |

### `rubrique`
| Attribut | 🔒 | Description |
|---|---|---|
| id, volet_id, code, label, poids/max_points, ordre | 🔴 | Ex. Scolaire=12, Socio-éco=13, Expérience=5, Langues=10, Motivation=15, Disponibilité=10 (Dossier) ; Présentation=8, Relationnel=10, Expression orale=8, Motivation entretien=9 (Entretien). |

### `item` (rubriques du volet Dossier)
| Attribut | 🔒 | Description |
|---|---|---|
| id, rubrique_id, code(SC.01...), label, type, max_points, notation_evaluateur, eliminatoire, eliminatoire_groupe | 🔴 | `eliminatoire_groupe` porte le groupement OR (DI.04/DI.05 = `acces_sites`). |

### `option_item`
| Attribut | 🔒 | Description |
|---|---|---|
| id, item_id, valeur, label, points, eliminatoire | 🔴 | Ex. SC.04=CEPE → points=0, eliminatoire=true. |

### `sous_critere_entretien` (rubriques du volet Entretien)
| Attribut | 🔒 |
|---|---|
| id, rubrique_id, code(PRES.01...), label, max_points | 🔴 |

### `critere_priorite`
Ordre de départage à égalité (mixité → vulnérabilité NEET → expérience secteur → motivation).

| Attribut | 🔒 |
|---|---|
| id, grille_id, ordre, code, label | 🔴 |

---

## 7. Décision & publication

### `publication`
**[Décision validée]** rattachée à la campagne (pas de flag global).

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| id | uuid | 🟡 (l'existence/la date de publication devient visible via le statut public, cf. ADR) | |
| campagne_id | fk, unique | | |
| publiee_le | timestamp | | |
| publiee_par | fk membre_equipe | 🔴 | L'auteur interne n'est jamais exposé au candidat. |

### `decision_candidature`
1-1 avec `candidature`. Sépare explicitement ce qui devient communicable de ce qui ne l'est jamais.

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| candidature_id | fk, 1-1 | | |
| rang | int | 🔴 | Classement interne — jamais communiqué (règle 1 : "classement" est dans la liste noire). |
| decision | enum(retenu, liste_attente, non_retenu, indisponible) | 🟡 | Visible **uniquement** si `campagne.publication` existe. Avant : jamais lu par un chemin candidat. |
| motif_interne | text, nullable | 🔴 | Jamais exposé, à aucun moment. |
| motif_communicable | text, nullable | 🟡 | Visible seulement après publication, seulement s'il est renseigné (sinon message générique côté client — texte fixe, pas une donnée). |

### `remplacement`
Trace un remplacement liste d'attente → retenu.

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| id | uuid | 🔴 | |
| candidature_indisponible_id, candidature_promue_id | fk candidature | 🔴 | |
| motif | text | 🔴 | |
| effectue_par, effectue_le | fk membre_equipe, timestamp | 🔴 | |

---

## 8. Audit

### `journal_audit`
Append-only (règle 5). Aucune route `UPDATE`/`DELETE` ne doit exister dessus (cf. ADR).

| Attribut | Type | 🔒 | Description |
|---|---|---|---|
| id | uuid | 🔴 | |
| auteur_id | fk utilisateur | 🔴 | |
| role | string | 🔴 | |
| action, module | string | 🔴 | |
| objet | string | 🔴 | Ex. numéro de dossier concerné. |
| ancienne_valeur, nouvelle_valeur | text, nullable | 🔴 | |
| motif | text, nullable | 🔴 | Obligatoire pour certaines actions (correction exceptionnelle, remplacement, élimination manuelle) — contrainte applicative, pas forcément `NOT NULL` en base (actions sans motif existent aussi, ex. "Connexion"). |
| resultat | string | 🔴 | |
| horodatage | timestamp | 🔴 | |
