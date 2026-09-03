/** Chemins de l'application (une seule source de vérité). */
export const paths = {
  home: '/',
  login: '/connexion',
  inscription: '/inscription',
  candidat: '/candidat',
  evaluateur: '/evaluateur',
  admin: '/admin',
}

/**
 * Espace d'accueil d'un rôle après connexion / en cas de redirection.
 *
 * NB (Lot 8a) : gardiennage STRICT par rôle principal. Le recouvrement
 * « administrateur ⊇ évaluateur » d'ADR-10 est une règle API ; il sera rebranché
 * côté navigation au Lot 8c quand les écrans d'évaluation arriveront.
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
