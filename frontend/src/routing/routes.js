/** Chemins de l'application (une seule source de vérité). */
export const paths = {
  home: '/',
  login: '/connexion',
  inscription: '/inscription',
  motDePasseOublie: '/mot-de-passe/oublie',
  motDePasseNouveau: '/mot-de-passe/nouveau',
  candidat: '/candidat',
  candidatureWizard: '/candidat/candidature',
  maCandidature: '/candidat/ma-candidature',
  candidatProfil: '/candidat/profil',
  candidatNotifications: '/candidat/notifications',
  evaluateur: '/evaluateur',
  evaluateurDossiers: '/evaluateur/mes-dossiers',
  admin: '/admin',
  adminCandidatures: '/admin/candidatures',
  adminFilieres: '/admin/filieres',
  adminCampagnes: '/admin/campagnes',
  adminEquipe: '/admin/equipe',
  adminRapports: '/admin/rapports',
  adminAudit: '/admin/audit',
  adminClassement: '/admin/classement',
}

/** Classement d'une campagne (Lot 8d-2) — segment dynamique. */
export function adminClassementPath(campagneId) {
  return `/admin/classement/${campagneId}`
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
 * Retour depuis la fiche/notation évaluateur (Lot 8d-1) — CONTINUITÉ de
 * navigation : un administrateur en recouvrement (ADR-10) qui a ouvert un
 * dossier depuis `/admin/candidatures` doit y revenir, pas atterrir dans
 * l'espace évaluateur qui n'est pas le sien (Étape 1, Q5). Pour un évaluateur,
 * inchangé : `/evaluateur/mes-dossiers`.
 */
export function retourListePath(role) {
  return role === 'administrateur' ? paths.adminCandidatures : paths.evaluateurDossiers
}

/** Libellé assorti à `retourListePath` — affiché dans la même logique. */
export function retourListeLabel(role) {
  return role === 'administrateur' ? 'Candidatures' : 'Mes dossiers'
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
