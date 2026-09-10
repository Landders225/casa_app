/**
 * Structure de navigation par espace — transcrite des layouts de la maquette
 * (assets/js/layout-{candidate,evaluator,admin}.js).
 *
 * Lot 8a : les entrées sont affichées (fidélité visuelle à la maquette) mais
 * inertes — les écrans métier arrivent aux Lots 8b/c/d. Seul « Tableau de bord »
 * est marqué actif (il pointe vers le placeholder de l'espace).
 */
export const navConfig = {
  candidat: {
    label: 'Espace candidat',
    sections: [
      {
        items: [
          { key: 'dashboard', icon: 'fa-gauge-high', label: 'Tableau de bord' },
          { key: 'candidature', icon: 'fa-file-lines', label: 'Ma candidature' },
          { key: 'profil', icon: 'fa-user', label: 'Mon profil' },
          { key: 'documents', icon: 'fa-folder-open', label: 'Documents' },
          { key: 'notifications', icon: 'fa-bell', label: 'Notifications' },
          { key: 'aide', icon: 'fa-circle-question', label: 'Aide' },
        ],
      },
    ],
  },
  evaluateur: {
    label: 'Espace évaluateur',
    sections: [
      {
        items: [
          { key: 'dashboard', icon: 'fa-gauge-high', label: 'Tableau de bord' },
          { key: 'candidatures', icon: 'fa-address-card', label: 'Candidatures' },
          { key: 'mes-dossiers', icon: 'fa-folder-open', label: 'Mes dossiers' },
          { key: 'entretiens', icon: 'fa-comments', label: 'Entretiens' },
          { key: 'evaluations', icon: 'fa-clipboard-check', label: 'Évaluations' },
          { key: 'classement', icon: 'fa-ranking-star', label: 'Classement' },
          { key: 'rapports', icon: 'fa-chart-column', label: 'Rapports' },
        ],
      },
    ],
  },
  administrateur: {
    label: 'Espace administrateur',
    sections: [
      {
        title: 'Pilotage',
        items: [
          { key: 'dashboard', icon: 'fa-gauge-high', label: 'Tableau de bord' },
          { key: 'campagnes', icon: 'fa-calendar-check', label: 'Campagnes' },
          { key: 'candidatures', icon: 'fa-address-card', label: 'Candidatures' },
          { key: 'classement', icon: 'fa-ranking-star', label: 'Classement' },
          { key: 'rapports', icon: 'fa-chart-column', label: 'Rapports' },
        ],
      },
      {
        title: 'Paramétrage',
        items: [
          // Lot 11b : un seul écran « Équipe » (évaluateurs + admins) remplace
          // les deux entrées inertes « Évaluateurs » / « Utilisateurs » de la
          // maquette — les candidats n'y sont pas gérés (D-11b-1).
          { key: 'equipe', icon: 'fa-users-gear', label: 'Équipe' },
          { key: 'cqp', icon: 'fa-layer-group', label: 'Filières CQP' },
          { key: 'audit', icon: 'fa-clipboard-list', label: "Journal d'audit" },
        ],
      },
    ],
  },
}

/** Libellé lisible du rôle (footer sidebar). */
export const roleLabel = {
  candidat: 'Candidat',
  evaluateur: 'Évaluateur',
  administrateur: 'Administrateur',
}
