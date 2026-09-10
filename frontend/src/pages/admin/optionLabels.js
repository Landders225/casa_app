/**
 * ==========================================================================
 * ESPACE ADMINISTRATEUR — libellés de présentation (Lot 8d-1).
 * ==========================================================================
 *
 * Duplique délibérément une partie de `pages/evaluateur/optionLabels.js`
 * (mêmes libellés, `statut_interne`/`statut_eligibilite_interne` ont le même
 * vocabulaire des deux côtés) plutôt que de l'importer : les trois arbres
 * (candidat / évaluateur / admin) restent étanches (cf. test de non-pont).
 * Aucun point de barème ici, uniquement des libellés d'affichage.
 */

/** `statut_interne` — vue admin (toutes valeurs possibles, y compris `non_eligible`). */
export const STATUT_INTERNE_LABELS = {
  brouillon: { label: 'Brouillon', badge: 'badge-neutral' },
  soumis: { label: 'Soumis', badge: 'badge-info' },
  en_instruction: { label: 'En cours d’instruction', badge: 'badge-warning' },
  evalue: { label: 'Évalué', badge: 'badge-primary' },
  non_eligible: { label: 'Non éligible', badge: 'badge-danger' },
}

/** `statut_eligibilite_interne` — déjà calculé serveur, jamais recalculé ici. */
export const ELIGIBILITE_LABELS = {
  non_verifie: { label: 'Non vérifiée', badge: 'badge-neutral' },
  eligible: { label: 'Éligible', badge: 'badge-success' },
  non_eligible: { label: 'Non éligible', badge: 'badge-danger' },
}

/** `decision_candidature.decision` — vue admin (peut être absente : dossier pas encore décidé). */
export const DECISION_LABELS = {
  retenu: { label: 'Retenu', badge: 'badge-success' },
  liste_attente: { label: 'Liste d’attente', badge: 'badge-warning' },
  non_retenu: { label: 'Non retenu', badge: 'badge-danger' },
  indisponible: { label: 'Indisponible', badge: 'badge-neutral' },
}

/**
 * Rôles d'un membre d'équipe (Lot 11b) — les SEULES valeurs proposées à la
 * création d'un compte via l'écran « Équipe ». « Candidat » est volontairement
 * absent : il ne se crée pas par cette voie (serveur : 422). L'ordre place le
 * rôle le moins privilégié en premier (défaut du `<select>`).
 */
export const ROLE_MEMBRE_LABELS = {
  evaluateur: 'Évaluateur',
  administrateur: 'Administrateur',
}

/** Badge de rôle dans la liste de l'équipe. */
export const ROLE_LABELS = {
  evaluateur: { label: 'Évaluateur', badge: 'badge-neutral' },
  administrateur: { label: 'Administrateur', badge: 'badge-primary' },
}

/**
 * Modules du journal d'audit (Lot 8d-1) — les libellés `module` en dur dans
 * chaque contrôleur qui écrit une ligne d'audit (Candidatures, Compte,
 * Entretien, Évaluation, Campagnes, Classement, Résultats, Filières). Noms de
 * CATÉGORIE de workflow, pas du contenu confidentiel — servent uniquement à
 * peupler le filtre `<select>` de l'écran Audit.
 */
export const AUDIT_MODULES = [
  'Candidatures',
  'Compte',
  'Utilisateurs',
  'Entretien',
  'Évaluation',
  'Campagnes',
  'Classement',
  'Résultats',
  'Filières',
]
