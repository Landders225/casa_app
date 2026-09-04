/** Date ISO 8601 -> « 14 mai 2026 » (fr-FR). Vide/invalide -> « — ». */
export function formatDateFr(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}
