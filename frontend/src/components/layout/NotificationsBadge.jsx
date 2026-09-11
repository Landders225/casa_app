import { useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'

/**
 * Pastille de notifications non lues (Lot 12c) — sur le lien « Notifications »
 * de la sidebar candidat UNIQUEMENT (seul rôle qui porte cette entrée dans
 * `navConfig` ; jamais monté pour évaluateur/admin). Se remonte à chaque
 * navigation (AppShell n'est pas un layout persistant, cf. AppShell.jsx) —
 * reste donc à jour sans polling.
 */
export function NotificationsBadge() {
  const [count, setCount] = useState(0)

  useEffect(() => {
    let alive = true
    Promise.resolve(apiClient.get('/candidat/notifications/compteur'))
      .then((res) => {
        if (alive) setCount(res?.data?.non_lues ?? 0)
      })
      .catch(() => {
        if (alive) setCount(0)
      })
    return () => {
      alive = false
    }
  }, [])

  if (count <= 0) return null

  return (
    <span className="badge badge-danger" aria-label={`${count} notification${count > 1 ? 's' : ''} non lue${count > 1 ? 's' : ''}`}>
      {count > 99 ? '99+' : count}
    </span>
  )
}
