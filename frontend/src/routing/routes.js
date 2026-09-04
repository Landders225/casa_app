/** Chemins de l'application (une seule source de vérité). */
export const paths = {
  home: '/',
  login: '/connexion',
  inscription: '/inscription',
  candidat: '/candidat',
  candidatureWizard: '/candidat/candidature',
  maCandidature: '/candidat/ma-candidature',
  evaluateur: '/evaluateur',
  evaluateurDossiers: '/evaluateur/mes-dossiers',
  admin: '/admin',
}

/** Fiche candidat vue par l'évaluateur — segment dynamique. */
export function evaluateurDossierPath(id) {
  return `/evaluateur/candidatures/${id}`
}

/** Notation du dossier /65 (Lot 8c-2) — segment dynamique. */
export function evaluateurEvaluationPath(id) {
  return `/evaluateur/candidatures/${id}/evaluation`
}

/** Entretien /35 (Lot 8c-2) — segment dynamique. */
export function evaluateurEntretienPath(id) {
  return `/evaluateur/candidatures/${id}/entretien`
}

/**
 * Espace d'accueil d'un rôle après connexion / en cas de redirection.
 *
 * NB (Lot 8a, rebranché au Lot 8c-1) : le recouvrement « administrateur ⊇
 * évaluateur » (ADR-10) est désormais actif aussi côté navigation — un
 * administrateur peut accéder aux routes /evaluateur/* (cf. App.jsx, roles
 * ['evaluateur','administrateur']). `roleHome` reste inchangé : l'accueil PAR
 * DÉFAUT d'un administrateur reste /admin, le recouvrement ne joue que lorsqu'il
 * navigue explicitement vers l'espace évaluateur.
 */
export function roleHome(role) {
  switch (role) {
    case 'candidat':
      return paths.candidat
    case 'evaluateur':
      return paths.evaluateur
    case 'administrateur':
      return paths.admin
    default:
      return paths.login
  }
}
