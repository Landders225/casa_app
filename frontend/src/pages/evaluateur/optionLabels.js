/**
 * ==========================================================================
 * ESPACE ÉVALUATEUR — libellés de présentation (Lot 8c-1).
 * ==========================================================================
 *
 * Ce fichier ne contient QUE des libellés d'affichage (aucune logique
 * d'éligibilité, aucun point de barème). Il est délibérément DUPLIQUÉ depuis
 * `pages/candidat/wizard/formStructure.js` plutôt que partagé : les deux arbres
 * (candidat / évaluateur) doivent rester étanches (cf. test de non-pont).
 *
 * ⚠️ SC.04 (plus haut diplôme) et son hint sont transcrits de
 * `App_maquette/assets/js/scoring.js` SANS les points (`cepe:0, cap:0,
 * bepc:1.5, bac:3, bt_bep:3`) — ils viendront, si besoin, de l'API au 8c-2,
 * jamais codés en dur ici.
 */

const OUI_NON = { oui: 'Oui', non: 'Non' }

export const SC02_CLASSE = {
  avant_3e: 'Antérieure à la 3ème',
  cap: 'Cycle CAP',
  '3e': '3ème',
  seconde: 'Seconde',
  '1ere': '1ère',
  terminale: 'Terminale',
  bt_bep: 'Cycle BT / BEP',
}

export const SE01_VIT_AVEC = { pere: 'Père', mere: 'Mère', les_deux: 'Les deux', aucun: 'Aucun' }

export const SE03_EMPLOI = {
  sans_emploi: 'Sans emploi / sans opportunité',
  stage: 'Stage',
  interim: 'Intérim',
  temps_partiel: 'Emploi à temps partiel',
  temps_plein: 'Emploi à temps plein',
}

export const SE04_REVENU = {
  parent: 'Un parent (biologique ou non)',
  conjoint: 'Mon/ma conjoint(e)',
  agr: 'Une activité génératrice de revenus',
  aucune: 'Aucune',
}

export const SE05_CHARGE = { '0': 'Aucune', '1-2': '1 à 2', '3+': '3 et plus' }

export const DI02_CONTRAINTES = {
  aucune: 'Aucune contrainte particulière',
  gerable: 'Une contrainte, mais que je peux gérer',
  bloquante: 'Une contrainte qui m’empêcherait de suivre la formation',
}

export const EXPERIENCE_DOMAINES = {
  hotellerie: 'Hôtellerie',
  restauration: 'Restauration',
  commerce: 'Commerce / accueil clientèle',
}

export const EXPERIENCE_DUREES = { moins_6: 'Moins de 6 mois', '6_12': '6 à 12 mois', plus_12: 'Plus de 12 mois' }

/** Échelle d'auto-évaluation Langues / Informatique (0 à 3), déclarée par le candidat. */
export const NIVEAUX = ['Débutant', 'Élémentaire', 'Intermédiaire', 'Avancé']

/** Les 6 pièces du dossier (référentiel `type_document`). */
export const PIECES_DOSSIER = [
  { code: 'cni', label: 'Carte Nationale d’Identité' },
  { code: 'residence', label: 'Certificat de résidence' },
  { code: 'diplome', label: 'Diplôme ou bulletin de notes' },
  { code: 'cv', label: 'Curriculum Vitae (CV)' },
  { code: 'lettre', label: 'Lettre de motivation' },
  { code: 'photo', label: 'Photo d’identité' },
]

/** SC.04 — vérifié par l'évaluateur, jamais déclaré par le candidat (ADR-07). */
export const SC04_DIPLOME = {
  label: 'Plus haut diplôme obtenu',
  hint: 'Vérifié par l’évaluateur à partir du diplôme/bulletin déposé — non déclaré par le candidat.',
  options: [
    { value: 'cepe', label: 'CEPE' },
    { value: 'cap', label: 'CAP' },
    { value: 'bepc', label: 'BEPC' },
    { value: 'bac', label: 'BAC' },
    { value: 'bt_bep', label: 'BT/BEP' },
  ],
}

/** Libellé d'une réponse à choix simple. `dict[value]`, ou la valeur brute, ou « — ». */
export function optionLabel(dict, value) {
  if (value === undefined || value === null || value === '') return '—'
  return dict[value] ?? value
}

export function ouiNonLabel(value) {
  return optionLabel(OUI_NON, value)
}

/** `statut_interne` tel que vu par l'évaluateur (brouillon/non_eligible-à-la-soumission exclus de sa liste). */
export const STATUT_INTERNE_LABELS = {
  soumis: { label: 'Soumis', badge: 'badge-info' },
  en_instruction: { label: 'En cours d’instruction', badge: 'badge-warning' },
  evalue: { label: 'Évalué', badge: 'badge-primary' },
}

/** `statut_eligibilite_interne` — déjà calculé serveur, jamais recalculé ici. */
export const ELIGIBILITE_LABELS = {
  non_verifie: { label: 'Non vérifiée', badge: 'badge-neutral' },
  eligible: { label: 'Éligible', badge: 'badge-success' },
  non_eligible: { label: 'Non éligible', badge: 'badge-danger' },
}

/** Origine d'un critère éliminatoire déclenché (`critere_eliminatoire_declenche.origine`). */
export const ORIGINE_LABELS = {
  soumission_candidat: 'À la soumission',
  verification_evaluateur: 'Vérification évaluateur',
  decision_administrative: 'Décision administrative',
}
