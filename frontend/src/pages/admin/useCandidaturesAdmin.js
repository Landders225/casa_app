import { useCallback, useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'

/**
 * Vue de supervision — `GET /api/admin/candidatures` (Lot 6a/8d-1). Même
 * patron que `useDossiers.js` (évaluateur, 8c-1) : ce hook ne fait que passer
 * les filtres et exposer la réponse telle quelle (pagination Laravel `data` +
 * `meta`) — `CandidatureAdminResource` a déjà tout calculé côté serveur.
 */
export function useCandidaturesAdmin({ statutInterne = '', filiere = '', evaluateur = '', campagne = '', page = 1 } = {}) {
  const [state, setState] = useState({ status: 'loading', items: [], meta: null })

  const load = useCallback(() => {
    let alive = true
    setState((s) => ({ ...s, status: 'loading' }))

    const qs = new URLSearchParams()
    if (statutInterne) qs.set('statut_interne', statutInterne)
    if (filiere) qs.set('filiere', filiere)
    if (evaluateur) qs.set('evaluateur', evaluateur)
    if (campagne) qs.set('campagne', campagne)
    if (page > 1) qs.set('page', String(page))
    const suffix = qs.toString() ? `?${qs.toString()}` : ''

    apiClient
      .get(`/admin/candidatures${suffix}`)
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
  }, [statutInterne, filiere, evaluateur, campagne, page])

  // oxlint-disable-next-line react/set-state-in-effect
  useEffect(() => load(), [load])

  return { ...state, reload: load }
}
