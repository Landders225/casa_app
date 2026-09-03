/**
 * Icône (Font Awesome) par code de filière — COSMÉTIQUE et CÔTÉ CLIENT.
 *
 * `GET /api/filieres` (liste blanche Lot 6a) ne renvoie que
 * { code, nom, description, actif } — ni icône, ni quota, ni compétences.
 * Cette table reprend les icônes de `App_maquette` / `FiliereSeeder` (D-8b1-3).
 */
const ICONS = {
  'accueil-reception': 'fa-bell-concierge',
  'entretien-hotelier': 'fa-broom',
  buanderie: 'fa-shirt',
  'restaurant-bar': 'fa-martini-glass-citrus',
  cuisine: 'fa-kitchen-set',
}

export function filiereIcon(code) {
  return ICONS[code] ?? 'fa-star'
}
