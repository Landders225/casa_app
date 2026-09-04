/**
 * ==========================================================================
 * STRUCTURE DU FORMULAIRE DE CANDIDATURE — libellés uniquement.
 * ==========================================================================
 *
 * ⚠️ CONFIDENTIALITÉ (ADR-02) — CE FICHIER NE CONTIENT AUCUN POINT.
 *
 * Transcription MANUELLE et vérifiée de la structure de
 * App_maquette/assets/js/scoring.js `CASA_GRILLE` (questions + options), DÉPOUILLÉE
 * de tout `points` / `max` / `poids` / `pointsParEtoile` / `notationEvaluateur`.
 * Le bundle JS est PUBLIC : aucune valeur de barème, aucune pondération, aucun
 * « /65 », ici ni ailleurs. Un test (smoke + Vitest) grep le bundle pour le
 * vérifier.
 *
 * Le backend ne sert pas la structure du formulaire (pas de `GET /api/grille`
 * pour un candidat) — d'où cette constante frontend. `field` = nom de colonne
 * `reponse_formulaire` (contrat des Lots 3a/3c) ; `value` = valeur d'énumération
 * autorisée (`ChampsFormulaire::ENUMS` côté backend).
 *
 * Volontairement ABSENTS (le candidat ne les saisit jamais — le backend les
 * refuse) : SC.04 plus haut diplôme, MO.04 note en étoiles, nationalité.
 */

const OUI_NON = [
  { value: 'oui', label: 'Oui' },
  { value: 'non', label: 'Non' },
]

/** Échelle d'auto-évaluation Langues / Informatique (0 à 3). */
export const NIVEAUX = ['Débutant', 'Élémentaire', 'Intermédiaire', 'Avancé']

/** Étape 3 — Profil scolaire. */
export const SCOLAIRE = {
  sc01: {
    field: 'sc01_scolarise_actuellement',
    label: 'Êtes-vous actuellement scolarisé(e) ?',
    control: 'segmented',
    options: OUI_NON,
  },
  sc02: {
    field: 'sc02_derniere_classe',
    label: 'Dernière classe fréquentée',
    control: 'cards',
    options: [
      { value: 'avant_3e', label: 'Antérieure à la 3ème' },
      { value: 'cap', label: 'Cycle CAP' },
      { value: '3e', label: '3ème' },
      { value: 'seconde', label: 'Seconde' },
      { value: '1ere', label: '1ère' },
      { value: 'terminale', label: 'Terminale' },
      { value: 'bt_bep', label: 'Cycle BT / BEP' },
    ],
  },
  sc03: {
    field: 'sc03_document_justifiant_niveau',
    label: 'Disposez-vous d’un document justifiant ce niveau ?',
    control: 'segmented',
    options: OUI_NON,
  },
  sc05: {
    field: 'sc05_beneficiaire_formation_actuelle',
    label: 'Bénéficiez-vous actuellement d’un programme de formation professionnelle ?',
    control: 'segmented',
    options: OUI_NON,
  },
  sc06: {
    field: 'sc06_deja_beneficie_formation',
    label: 'Avez-vous déjà bénéficié d’un programme de formation professionnelle ?',
    control: 'segmented',
    options: OUI_NON,
  },
  sc07: {
    field: 'sc07_filiere_suivie',
    label: 'Filière et certification suivies',
    control: 'text',
    maxLength: 2000,
  },
  sc08: {
    field: 'sc08_mene_a_terme',
    label: 'Avez-vous mené ce programme à terme ?',
    control: 'segmented',
    options: OUI_NON,
  },
  sc09: {
    field: 'sc09_motif_non_achevement',
    label: 'Motif de non-achèvement',
    control: 'text',
    maxLength: 2000,
  },
}

/** Étape 4 — Situation socio-économique. */
export const SOCIO_ECO = {
  se01: {
    field: 'se01_vit_avec',
    label: 'Avec lequel de vos parents biologiques vivez-vous ?',
    control: 'cards',
    optional: true,
    options: [
      { value: 'pere', label: 'Père' },
      { value: 'mere', label: 'Mère' },
      { value: 'les_deux', label: 'Les deux' },
      { value: 'aucun', label: 'Aucun' },
    ],
  },
  se02: {
    field: 'se02_orphelin',
    label: 'Êtes-vous orphelin(e) ?',
    control: 'segmented',
    options: OUI_NON,
  },
  se03: {
    field: 'se03_situation_emploi',
    label: 'Situation d’emploi actuelle',
    control: 'cards',
    options: [
      { value: 'sans_emploi', label: 'Sans emploi / sans opportunité' },
      { value: 'stage', label: 'Stage' },
      { value: 'interim', label: 'Intérim' },
      { value: 'temps_partiel', label: 'Emploi à temps partiel' },
      { value: 'temps_plein', label: 'Emploi à temps plein' },
    ],
  },
  se04: {
    field: 'se04_source_revenu',
    label: 'Principale source de revenu',
    control: 'cards',
    options: [
      { value: 'parent', label: 'Un parent (biologique ou non)' },
      { value: 'conjoint', label: 'Mon/ma conjoint(e)' },
      { value: 'agr', label: 'Une activité génératrice de revenus' },
      { value: 'aucune', label: 'Aucune' },
    ],
  },
  se05: {
    field: 'se05_personnes_a_charge',
    label: 'Nombre de personnes à votre charge',
    control: 'cards',
    optional: true,
    options: [
      { value: '0', label: 'Aucune' },
      { value: '1-2', label: '1 à 2' },
      { value: '3+', label: '3 et plus' },
    ],
  },
  se06: {
    field: 'se06_soutien_menage',
    label: 'Êtes-vous le soutien principal du ménage ?',
    control: 'segmented',
    options: OUI_NON,
  },
}

/** Étape 6 — Langues & informatique (auto-évaluation, échelle NIVEAUX). */
export const LANGUES = [
  { field: 'langue_ecrit', label: 'Français écrit' },
  { field: 'langue_parle', label: 'Français parlé' },
  { field: 'langue_comprehension', label: 'Compréhension orale' },
  { field: 'info_word', label: 'Word (traitement de texte)' },
  { field: 'info_excel', label: 'Excel (tableur)' },
  { field: 'info_internet', label: 'Internet & messagerie' },
]

/** Étape 7 — Motivation : lettre. Le classement des filières est géré à part. */
export const MOTIVATION = {
  mo04: {
    field: 'mo04_lettre_motivation',
    label: 'Pourquoi souhaitez-vous suivre cette formation ?',
    control: 'text',
    maxLength: 500,
    hint: 'Expliquez en quelques phrases votre motivation. Ce texte sera lu par l’équipe d’évaluation.',
    placeholder: 'Ex. : je souhaite intégrer ce programme pour…',
  },
}

/** Étape 8 — Disponibilité. */
export const DISPONIBILITE = {
  di01: {
    field: 'di01_disponible_lun_ven',
    label: 'Êtes-vous disponible du lundi au vendredi pendant toute la durée de la formation ?',
    control: 'segmented',
    options: OUI_NON,
  },
  di02: {
    field: 'di02_contraintes',
    label: 'Avez-vous des contraintes familiales ou professionnelles ?',
    control: 'cards',
    options: [
      { value: 'aucune', label: 'Aucune contrainte particulière' },
      { value: 'gerable', label: 'Une contrainte, mais que je peux gérer' },
      { value: 'bloquante', label: 'Une contrainte qui m’empêcherait de suivre la formation' },
    ],
  },
  di03: {
    field: 'di03_engagement_complet',
    label: 'Vous engagez-vous à suivre l’intégralité de la formation ?',
    control: 'segmented',
    options: OUI_NON,
  },
  di04: {
    field: 'acces_plateau',
    label: 'Pouvez-vous vous rendre au Plateau pour la formation ?',
    control: 'segmented',
    options: OUI_NON,
  },
  di05: {
    field: 'acces_deux_plateaux_vallons',
    label: 'Pouvez-vous vous rendre aux 2 Plateaux Vallons pour la formation ?',
    control: 'segmented',
    options: OUI_NON,
  },
}

/** Étape 5 — Expérience professionnelle (contrat `ExperienceRequest`). */
export const EXPERIENCE_DOMAINES = [
  { value: 'hotellerie', label: 'Hôtellerie' },
  { value: 'restauration', label: 'Restauration' },
  { value: 'commerce', label: 'Commerce / accueil clientèle' },
]

export const EXPERIENCE_DUREES = [
  { value: 'moins_6', label: 'Moins de 6 mois' },
  { value: '6_12', label: '6 à 12 mois' },
  { value: 'plus_12', label: 'Plus de 12 mois' },
]

/** Étape 9 — Pièces du dossier (référentiel `type_document`, 6 obligatoires). */
export const PIECES_DOSSIER = [
  { code: 'cni', label: 'Carte Nationale d’Identité' },
  { code: 'residence', label: 'Certificat de résidence' },
  { code: 'diplome', label: 'Diplôme ou bulletin de notes' },
  { code: 'cv', label: 'Curriculum Vitae (CV)' },
  { code: 'lettre', label: 'Lettre de motivation' },
  { code: 'photo', label: 'Photo d’identité' },
]

/** Formats acceptés par l'upload (backend `ContraintesFichier`). */
export const UPLOAD = {
  accept: '.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png',
  mimes: ['application/pdf', 'image/jpeg', 'image/png'],
  maxBytes: 10 * 1024 * 1024,
  label: 'PDF, JPEG ou PNG — 10 Mo maximum',
}

/** Étapes du wizard (ordre = maquette candidature.html). */
export const STEPS = [
  { key: 'identite', label: 'Informations personnelles' },
  { key: 'filiere', label: 'Filière souhaitée' },
  { key: 'scolaire', label: 'Profil scolaire' },
  { key: 'socioEco', label: 'Situation socio-économique' },
  { key: 'experience', label: 'Expérience professionnelle' },
  { key: 'langues', label: 'Langues & informatique' },
  { key: 'motivation', label: 'Motivation' },
  { key: 'disponibilite', label: 'Disponibilité' },
  { key: 'documents', label: 'Pièces justificatives' },
  { key: 'recap', label: 'Récapitulatif' },
]

export const stepIndex = (key) => STEPS.findIndex((s) => s.key === key)
