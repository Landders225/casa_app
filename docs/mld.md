# CASA — Modèle Logique de Données (MLD)

Conçu au Lot 0. Format DDL PostgreSQL "prêt à traduire" en migrations Laravel. Types génériques : `uuid` (PK, `gen_random_uuid()`), `timestamptz`, `numeric(p,s)` pour les scores.

> **Lot 1 (fait).** Ce MLD est désormais traduit en migrations Laravel réelles (`backend/database/migrations/2026_09_02_1000*`), testées contre le conteneur PostgreSQL. Deux points ont été finalisés par rapport au Lot 0 : la contrainte d'exclusivité de `piece_justificative` (colonne discriminante `rattachement`, cf. §3) et l'immuabilité du `journal_audit` (triggers PostgreSQL, cf. §8 et ADR-12). Ce document reste la source de vérité : il est mis à jour en même temps que les migrations.

Chaque colonne porte son marqueur de confidentialité (🟢/🟡/🔴, cf. `docs/dictionnaire-donnees.md`). **Aucune colonne 🔴 ne doit jamais apparaître dans une API Resource exposée au rôle `candidat`** — c'est la checklist à cocher au Lot d'implémentation des endpoints.

---

## 1. Identité & rôles

```sql
CREATE TABLE utilisateur (
  id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  email               varchar(255) UNIQUE NOT NULL,          -- 🟢
  mot_de_passe_hash   varchar(255) NOT NULL,                 -- 🔴
  role                varchar(20) NOT NULL CHECK (role IN ('candidat','evaluateur','administrateur')), -- 🟢
  actif               boolean NOT NULL DEFAULT true,         -- 🟢
  cree_le             timestamptz NOT NULL DEFAULT now(),    -- 🟢
  derniere_connexion_le timestamptz,                         -- 🟢
  created_at timestamptz, updated_at timestamptz
);

CREATE TABLE candidat (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  utilisateur_id    uuid NOT NULL UNIQUE REFERENCES utilisateur(id), -- 🟢
  prenom            varchar(100) NOT NULL,   -- 🟢
  nom               varchar(100) NOT NULL,   -- 🟢
  sexe              char(1) NOT NULL CHECK (sexe IN ('F','H')), -- 🟢
  date_naissance    date NOT NULL,           -- 🟢
  cni               varchar(50) NOT NULL,    -- 🟢
  telephone         varchar(20) NOT NULL,    -- 🟢
  ville_residence   varchar(100) NOT NULL,   -- 🟢 (FK logique vers référentiel `ville`, non détaillé ici — hors périmètre confidentialité)
  residence_ci      boolean NOT NULL,        -- 🟢
  created_at timestamptz, updated_at timestamptz
);

CREATE TABLE membre_equipe (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  utilisateur_id    uuid NOT NULL UNIQUE REFERENCES utilisateur(id),
  prenom            varchar(100) NOT NULL,
  nom               varchar(100) NOT NULL,
  poste             varchar(150) NOT NULL,
  created_at timestamptz, updated_at timestamptz
);
-- Le sous-type exact (évaluateur / administrateur) est lu sur utilisateur.role,
-- pas dupliqué ici (évite une désynchronisation entre les deux colonnes).
```

## 2. Filières & campagnes

```sql
CREATE TABLE filiere (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  code          varchar(50) UNIQUE NOT NULL,   -- 🟢
  nom           varchar(150) NOT NULL,         -- 🟢
  description   text NOT NULL,                 -- 🟢
  icone         varchar(50),                   -- 🟢
  actif         boolean NOT NULL DEFAULT true, -- 🟢
  created_at timestamptz, updated_at timestamptz
);

CREATE TABLE filiere_competence (            -- 🟢 (normalise le tableau `competences` de la maquette)
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  filiere_id  uuid NOT NULL REFERENCES filiere(id),
  libelle     varchar(150) NOT NULL,
  ordre       smallint NOT NULL DEFAULT 0
);

CREATE TABLE campagne (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  nom               varchar(150) NOT NULL,     -- 🟢
  statut            varchar(20) NOT NULL CHECK (statut IN ('brouillon','ouverte','cloturee')), -- 🟢
  date_ouverture    date NOT NULL,             -- 🟢
  date_cloture      date NOT NULL,             -- 🟢
  places_totales    integer NOT NULL,          -- 🟢
  description       text,                      -- 🟢
  created_at timestamptz, updated_at timestamptz
);

CREATE TABLE campagne_filiere (
  campagne_id   uuid NOT NULL REFERENCES campagne(id), -- 🟢
  filiere_id    uuid NOT NULL REFERENCES filiere(id),  -- 🟢
  quota         integer NOT NULL,                       -- 🟢
  PRIMARY KEY (campagne_id, filiere_id)
);
```

## 3. Candidature & réponses au formulaire

```sql
CREATE TABLE candidature (
  id                        uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  candidat_id               uuid NOT NULL REFERENCES candidat(id),   -- 🟢
  campagne_id               uuid NOT NULL REFERENCES campagne(id),   -- 🟢
  filiere_id                uuid NOT NULL REFERENCES filiere(id),    -- 🟢
  numero_dossier            varchar(30) UNIQUE NOT NULL,             -- 🟢
  statut_interne            varchar(20) NOT NULL DEFAULT 'brouillon' -- 🔴 JAMAIS renvoyé brut au candidat, cf. ADR "Statut public dérivé côté serveur"
      CHECK (statut_interne IN ('brouillon','soumis','en_instruction','non_eligible','evalue')),
  statut_eligibilite_interne varchar(20) NOT NULL DEFAULT 'non_verifie' -- 🔴
      CHECK (statut_eligibilite_interne IN ('non_verifie','eligible','non_eligible')),
  date_soumission           timestamptz,           -- 🟢
  dossier_verrouille        boolean NOT NULL DEFAULT false, -- 🔴
  dossier_verrouille_le     timestamptz,            -- 🔴
  dossier_verrouille_par    uuid REFERENCES membre_equipe(id), -- 🔴
  evaluateur_id             uuid REFERENCES membre_equipe(id), -- 🔴
  commentaire_evaluateur    text,                   -- 🔴
  date_evaluation           date,                   -- 🔴
  cqp_confirme              boolean NOT NULL DEFAULT false, -- 🟢 (confirmation de filière, étape 2/10, non modifiable après)
  created_at timestamptz, updated_at timestamptz
);
CREATE INDEX ON candidature (candidat_id);
CREATE INDEX ON candidature (campagne_id, filiere_id);

CREATE TABLE reponse_formulaire (              -- 🔴 (table entière)
  candidature_id    uuid PRIMARY KEY REFERENCES candidature(id),
  sc01_scolarise_actuellement    varchar(10) CHECK (sc01_scolarise_actuellement IN ('oui','non')),
  sc02_derniere_classe           varchar(20) CHECK (sc02_derniere_classe IN ('avant_3e','cap','3e','seconde','1ere','terminale','bt_bep')),
  sc03_document_justifiant_niveau varchar(10) CHECK (sc03_document_justifiant_niveau IN ('oui','non')),
  sc05_beneficiaire_formation_actuelle varchar(10) CHECK (sc05_beneficiaire_formation_actuelle IN ('oui','non')),
  sc06_deja_beneficie_formation  varchar(10),
  sc07_filiere_suivie            text,
  sc08_mene_a_terme              varchar(10),
  sc09_motif_non_achevement      text,
  se01_vit_avec                  varchar(10) CHECK (se01_vit_avec IN ('pere','mere','les_deux','aucun')),
  se02_orphelin                  varchar(10) CHECK (se02_orphelin IN ('oui','non')),
  se03_situation_emploi          varchar(20) CHECK (se03_situation_emploi IN ('sans_emploi','stage','interim','temps_partiel','temps_plein')),
  se04_source_revenu             varchar(10) CHECK (se04_source_revenu IN ('parent','conjoint','agr','aucune')),
  se05_personnes_a_charge        varchar(5)  CHECK (se05_personnes_a_charge IN ('0','1-2','3+')),
  se06_soutien_menage            varchar(10) CHECK (se06_soutien_menage IN ('oui','non')),
  langue_ecrit          smallint CHECK (langue_ecrit BETWEEN 0 AND 3),
  langue_parle          smallint CHECK (langue_parle BETWEEN 0 AND 3),
  langue_comprehension  smallint CHECK (langue_comprehension BETWEEN 0 AND 3),
  info_word             smallint CHECK (info_word BETWEEN 0 AND 3),
  info_excel            smallint CHECK (info_excel BETWEEN 0 AND 3),
  info_internet         smallint CHECK (info_internet BETWEEN 0 AND 3),
  acces_plateau              varchar(10) CHECK (acces_plateau IN ('oui','non')),
  acces_deux_plateaux_vallons varchar(10) CHECK (acces_deux_plateaux_vallons IN ('oui','non')),
  mo04_lettre_motivation     varchar(500),   -- longueur max alignée sur la maquette (règle produit)
  mo04_note_etoiles          smallint CHECK (mo04_note_etoiles BETWEEN 0 AND 5), -- saisie évaluateur uniquement (contrainte applicative)
  di01_disponible_lun_ven    varchar(10) CHECK (di01_disponible_lun_ven IN ('oui','non')),
  di02_contraintes           varchar(10) CHECK (di02_contraintes IN ('aucune','gerable','bloquante')),
  di03_engagement_complet    varchar(10) CHECK (di03_engagement_complet IN ('oui','non')),
  updated_at timestamptz
);

CREATE TABLE experience_professionnelle (      -- 🔴 (table entière)
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  candidature_id    uuid NOT NULL REFERENCES candidature(id),
  domaine           varchar(20) NOT NULL CHECK (domaine IN ('hotellerie','restauration','commerce')),
  duree_categorie   varchar(10) NOT NULL CHECK (duree_categorie IN ('moins_6','6_12','plus_12')),
  piece_justificative_id uuid UNIQUE REFERENCES piece_justificative(id), -- nullable depuis le Lot 3a (voir note)
  created_at timestamptz
);
-- Note (Lot 3a) : `piece_justificative_id` était NOT NULL au Lot 0. Rendu
-- nullable (migration ..._make_experience_piece_justificative_nullable) : le
-- brouillon de candidature permet de déclarer une expérience AVANT d'y attacher
-- son justificatif (upload = Lot 3b). La règle « 1 expérience = 1 justificatif »
-- (MCD) devient une VALIDATION À LA SOUMISSION (Lot 3c), pas une contrainte de
-- colonne. L'index UNIQUE est conservé (une pièce ne sert qu'à une expérience).

CREATE TABLE classement_filiere_preference (   -- 🟢 (préférence exprimée par le candidat lui-même)
  candidature_id  uuid NOT NULL REFERENCES candidature(id),
  filiere_id      uuid NOT NULL REFERENCES filiere(id),
  rang            smallint NOT NULL CHECK (rang BETWEEN 1 AND 5),
  PRIMARY KEY (candidature_id, filiere_id),
  UNIQUE (candidature_id, rang)
);

CREATE TABLE type_document (                   -- 🟢 référentiel
  code    varchar(20) PRIMARY KEY,              -- cni, residence, diplome, cv, lettre, photo
  libelle varchar(150) NOT NULL
);

CREATE TABLE piece_justificative (
  id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  candidature_id      uuid REFERENCES candidature(id),       -- 🟢 nullable : renseigné SSI pièce du dossier
  type_document_code  varchar(20) REFERENCES type_document(code), -- 🟢 nullable SSI justificatif d'expérience (pas de "type" référentiel)
  rattachement        varchar(12) NOT NULL CHECK (rattachement IN ('dossier','experience')), -- 🟢 colonne discriminante (finalisation Lot 1)
  nom_original        varchar(255) NOT NULL,   -- 🟢 nom client assaini (affichage seul, jamais un chemin)
  chemin_stockage     varchar(500) NOT NULL,   -- 🔴 {candidature_id}/{uuid}.{ext} sur le disque privé `documents`, hors webroot (ADR-11)
  taille_octets       integer NOT NULL,        -- 🟢
  type_mime           varchar(100) NOT NULL,   -- 🟢 MIME détecté par contenu à l'upload (Lot 3b) — sert le Content-Type au téléchargement
  depose_le           timestamptz NOT NULL DEFAULT now(), -- 🟢
  -- Exclusivité dossier XOR expérience, désormais exécutable (colonne
  -- discriminante — option prévue par le MLD Lot 0, retenue au Lot 1) :
  CONSTRAINT piece_rattachee_dossier_xor_experience CHECK (
       (rattachement = 'dossier'    AND candidature_id IS NOT NULL AND type_document_code IS NOT NULL)
    OR (rattachement = 'experience' AND candidature_id IS NULL     AND type_document_code IS NULL)
  )
  -- Le rattachement effectif d'une pièce 'experience' se fait via
  -- experience_professionnelle.piece_justificative_id (NOT NULL + UNIQUE :
  -- une pièce ne sert qu'à une seule expérience).
);
```

## 4. Vérification & évaluation du dossier

```sql
CREATE TABLE verification_dossier (            -- 🔴 (table entière)
  candidature_id       uuid PRIMARY KEY REFERENCES candidature(id),
  nationalite_confirmee boolean,
  diplome_verifie       varchar(10) CHECK (diplome_verifie IN ('cepe','cap','bepc','bac','bt_bep')),
  verifie_par           uuid REFERENCES membre_equipe(id),
  verifie_le            timestamptz
);

CREATE TABLE evaluation_dossier (              -- 🔴 (table entière)
  candidature_id  uuid PRIMARY KEY REFERENCES candidature(id),
  grille_id       uuid NOT NULL REFERENCES grille(id),   -- snapshot de version, jamais recalculé après verrouillage
  score_total     numeric(4,1) NOT NULL,                  -- /65
  valide          boolean NOT NULL DEFAULT false,
  valide_le       timestamptz,
  valide_par      uuid REFERENCES membre_equipe(id)
);

CREATE TABLE score_rubrique_dossier (          -- 🔴
  evaluation_dossier_id  uuid NOT NULL REFERENCES evaluation_dossier(candidature_id),
  rubrique_id            uuid NOT NULL REFERENCES rubrique(id),
  score_obtenu           numeric(6,4) NOT NULL, -- Lot 4b (D-4b-1) : élargi de numeric(4,2) — scoring.js
                                                -- produit des décimales périodiques (experience, langues :
                                                -- 6/9×10 = 6,6667). evaluation_dossier.score_total reste
                                                -- numeric(4,1), arrondi 1 décimale comme scoring.js.
  PRIMARY KEY (evaluation_dossier_id, rubrique_id)
);

CREATE TABLE critere_eliminatoire_declenche (  -- 🔴
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  candidature_id  uuid NOT NULL REFERENCES candidature(id),
  code_critere    varchar(50) NOT NULL,        -- ex. 'DI.01', 'acces_sites', 'age_min'
  detail          text NOT NULL,
  origine         varchar(30) NOT NULL CHECK (origine IN ('soumission_candidat','verification_evaluateur')),
  declenche_le    timestamptz NOT NULL DEFAULT now()
);
```

## 5. Entretien

```sql
CREATE TABLE entretien (                       -- 🔴 (table entière)
  candidature_id  uuid PRIMARY KEY REFERENCES candidature(id),
  statut          varchar(20) NOT NULL CHECK (statut IN ('planifie','realise','valide')),
  date            date NOT NULL,
  heure           time NOT NULL,
  lieu            varchar(100) NOT NULL,        -- 'Le Plateau' | '2 Plateaux Vallons'
  evaluateur_id   uuid NOT NULL REFERENCES membre_equipe(id),
  presence        varchar(10) CHECK (presence IN ('present','absent')),
  observation     text,
  grille_id       uuid REFERENCES grille(id),
  score_total     numeric(4,1),                 -- /35
  valide_le       timestamptz,
  valide_par      uuid REFERENCES membre_equipe(id)
);

CREATE TABLE note_sous_critere_entretien (      -- 🔴
  entretien_id      uuid NOT NULL REFERENCES entretien(candidature_id),
  sous_critere_id   uuid NOT NULL REFERENCES sous_critere_entretien(id),
  points_attribues  numeric(3,1) NOT NULL,      -- Lot 4c : 0..max du sous-critère (borné, 422 si dépassé)
  PRIMARY KEY (entretien_id, sous_critere_id)
  -- D-4c-4 : le volet Entretien compte 12 sous-critères (PRES/REL/EO/MOE × 3),
  -- pas « 10 » (erreur des commentaires initiaux). Source de vérité : scoring.js
  -- (CASA_GRILLE, volet entretien) — Σ maxima = 8+10+8+9 = 35.
);
```

## 6. Barème (versionné — règle 6)

```sql
CREATE TABLE grille (                           -- 🔴 (groupe entier : barème)
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  version     integer NOT NULL,
  label       varchar(150) NOT NULL,
  date_effet  date NOT NULL,
  actif       boolean NOT NULL DEFAULT false,
  created_at timestamptz
);
CREATE UNIQUE INDEX one_active_grille ON grille (actif) WHERE actif = true; -- une seule version active

CREATE TABLE volet (                            -- 🔴
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  grille_id   uuid NOT NULL REFERENCES grille(id),
  code        varchar(20) NOT NULL CHECK (code IN ('dossier','entretien')),
  label       varchar(100) NOT NULL,
  max_points  numeric(4,1) NOT NULL,             -- 65 ou 35
  UNIQUE (grille_id, code)
);

CREATE TABLE rubrique (                         -- 🔴
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  volet_id    uuid NOT NULL REFERENCES volet(id),
  code        varchar(30) NOT NULL,              -- 'scolaire', 'presentation'...
  label       varchar(150) NOT NULL,
  max_points  numeric(4,1) NOT NULL,              -- poids : 12,13,5,10,15,10 / 8,10,8,9
  ordre       smallint NOT NULL DEFAULT 0,
  UNIQUE (volet_id, code)
);

CREATE TABLE item (                             -- 🔴 (volet Dossier uniquement)
  id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  rubrique_id           uuid NOT NULL REFERENCES rubrique(id),
  code                  varchar(20) NOT NULL,     -- 'SC.01'...
  label                 varchar(255) NOT NULL,
  type                  varchar(20) NOT NULL,     -- choix | derive | texte | texte_note | document
  max_points            numeric(4,2),
  notation_evaluateur   boolean NOT NULL DEFAULT false,
  notee                 boolean NOT NULL DEFAULT true,
  eliminatoire          boolean NOT NULL DEFAULT false,
  eliminatoire_groupe   varchar(30),               -- ex. 'acces_sites' (DI.04/DI.05, logique OR)
  UNIQUE (rubrique_id, code)
);

CREATE TABLE option_item (                      -- 🔴
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  item_id       uuid NOT NULL REFERENCES item(id),
  valeur        varchar(30) NOT NULL,
  label         varchar(150) NOT NULL,
  points        numeric(4,2) NOT NULL DEFAULT 0,
  eliminatoire  boolean NOT NULL DEFAULT false,
  UNIQUE (item_id, valeur)
);

CREATE TABLE sous_critere_entretien (           -- 🔴 (volet Entretien uniquement)
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  rubrique_id uuid NOT NULL REFERENCES rubrique(id),
  code        varchar(20) NOT NULL,              -- 'PRES.01'...
  label       varchar(255) NOT NULL,
  max_points  numeric(3,1) NOT NULL,
  UNIQUE (rubrique_id, code)
);

CREATE TABLE critere_priorite (                 -- 🔴
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  grille_id  uuid NOT NULL REFERENCES grille(id),
  ordre      smallint NOT NULL,
  code       varchar(30) NOT NULL,               -- mixite | vulnerabilite | experience_secteur | motivation
  label      varchar(150) NOT NULL,
  UNIQUE (grille_id, ordre)
);
```

## 7. Décision & publication

```sql
CREATE TABLE publication (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  campagne_id   uuid NOT NULL UNIQUE REFERENCES campagne(id), -- 🟡 (existence lue via statut public, cf. ADR)
  publiee_le    timestamptz NOT NULL DEFAULT now(),           -- 🟡
  publiee_par   uuid NOT NULL REFERENCES membre_equipe(id)    -- 🔴
);

CREATE TABLE decision_candidature (
  candidature_id      uuid PRIMARY KEY REFERENCES candidature(id),
  rang                integer,                                 -- 🔴 jamais communiqué ; NULL pour un non-éligible
                                                               --    (décision non_retenu explicite mais non classé, Lot 5a / D-5a-4)
  decision            varchar(20) NOT NULL                     -- 🟡 visible seulement si publication existe
      CHECK (decision IN ('retenu','liste_attente','non_retenu','indisponible')),
  motif_interne       text,                                    -- 🔴 jamais exposé
  motif_communicable  text                                     -- 🟡 visible après publication uniquement, si non NULL
);

CREATE TABLE remplacement (                     -- 🔴 (table entière)
  id                            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  candidature_indisponible_id   uuid NOT NULL REFERENCES candidature(id),
  candidature_promue_id         uuid REFERENCES candidature(id), -- nullable : peut n'y avoir aucun candidat en liste d'attente à promouvoir
  motif                         text NOT NULL,
  effectue_par                  uuid NOT NULL REFERENCES membre_equipe(id),
  effectue_le                   timestamptz NOT NULL DEFAULT now()
);
```

## 8. Audit

```sql
CREATE TABLE journal_audit (                    -- 🔴 (table entière) — APPEND-ONLY
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  auteur_id        uuid NOT NULL REFERENCES utilisateur(id),
  role             varchar(20) NOT NULL,
  action           varchar(150) NOT NULL,
  module           varchar(100) NOT NULL,
  objet            varchar(150),
  ancienne_valeur  text,
  nouvelle_valeur  text,
  motif            text,
  resultat         varchar(100) NOT NULL DEFAULT 'Succès',
  horodatage       timestamptz NOT NULL DEFAULT now()
);
-- Immuabilité garantie à deux niveaux (cf. ADR-12) :
--  1) applicatif : absence totale de route/policy de modification sur ce modèle ;
--  2) PostgreSQL : triggers BEFORE UPDATE OR DELETE (par ligne) + BEFORE TRUNCATE
--     (par instruction) qui lèvent RAISE EXCEPTION. Seul INSERT est permis.
--     Migration dédiée : ..._add_journal_audit_append_only_trigger.
```

---

## Note d'ordre de création (dépendances circulaires apparentes)

`experience_professionnelle.piece_justificative_id` référence `piece_justificative`, qui elle-même référence `candidature` (nullable) — pas de cycle réel, mais l'ordre de création des migrations doit être : `candidature` → `piece_justificative` → `experience_professionnelle`. `grille`/`volet`/`rubrique`/`item`/`option_item`/`sous_critere_entretien`/`critere_priorite` doivent être créées et peuplées **avant** toute campagne ouverte (le barème doit exister avant la première candidature évaluable).
