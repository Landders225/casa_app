import { useCallback, useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

/**
 * Campagnes — `GET /api/admin/campagnes` (Lot 8d-1, nouveau) +
 * `PATCH /api/admin/campagnes/{id}` (Lot 6a, transitions). Alimente l'écran
 * Campagnes et le filtre `?campagne=` de la vue supervision. Le hook ne fait
 * que passer le body de la transition et remplacer la ligne concernée par la
 * réponse serveur — aucune règle de transition n'est devinée côté client, le
 * bouton proposé dépend uniquement de `statut` déjà renvoyé par l'API.
 */
export function useCampagnes() {
  const [state, setState] = useState({ status: 'loading', items: [] })
  const [changing, setChanging] = useState(null) // id de la campagne en cours de transition
  const [error, setError] = useState(null)

  const load = useCallback(() => {
    let alive = true
    apiClient
      .get('/admin/campagnes')
      .then((res) => {
        if (alive) setState({ status: 'ready', items: res.data ?? [] })
      })
      .catch(() => {
        if (alive) setState({ status: 'error', items: [] })
      })
    return () => {
      alive = false
    }
  }, [])

  // oxlint-disable-next-line react/set-state-in-effect
  useEffect(() => load(), [load])

  const changerStatut = useCallback(async (id, statut) => {
    setChanging(id)
    setError(null)
    try {
      const res = await apiClient.patch(`/admin/campagnes/${id}`, { statut })
      setState((s) => ({
        ...s,
        items: s.items.map((c) => (c.id === id ? { ...c, ...res.data } : c)),
      }))
      return res.data
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'La transition a échoué.')
      throw err
    } finally {
      setChanging(null)
    }
  }, [])

  return { ...state, changing, error, changerStatut, reload: load }
}
