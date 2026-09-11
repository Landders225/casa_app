import { useCallback, useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'

/**
 * Historique in-app des notifications (Lot 12c) — `GET /api/candidat/notifications`
 * (paginé, déjà trié par date décroissante côté serveur — ce hook ne trie rien
 * lui-même), `PATCH .../{id}/lue`, `POST .../marquer-tout-lu`.
 */
export function useNotifications({ page = 1 } = {}) {
  const [state, setState] = useState({ status: 'loading', items: [], meta: null })

  const load = useCallback(() => {
    let alive = true
    setState((s) => ({ ...s, status: 'loading' }))

    const suffix = page > 1 ? `?page=${page}` : ''
    apiClient
      .get(`/candidat/notifications${suffix}`)
      .then((res) => {
        if (!alive) return
        setState({ status: 'ready', items: res.data ?? [], meta: res.meta ?? null })
      })
      .catch(() => {
        if (alive) setState({ status: 'error', items: [], meta: null })
      })

    return () => {
      alive = false
    }
  }, [page])

  // oxlint-disable-next-line react/set-state-in-effect
  useEffect(() => load(), [load])

  const marquerLue = useCallback(async (id) => {
    await apiClient.patch(`/candidat/notifications/${id}/lue`)
    setState((s) => ({
      ...s,
      items: s.items.map((n) => (n.id === id ? { ...n, lue: true } : n)),
    }))
  }, [])

  const marquerToutLu = useCallback(async () => {
    await apiClient.post('/candidat/notifications/marquer-tout-lu')
    setState((s) => ({ ...s, items: s.items.map((n) => ({ ...n, lue: true })) }))
  }, [])

  return { ...state, reload: load, marquerLue, marquerToutLu }
}
