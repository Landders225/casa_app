import { useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'

/**
 * Liste des dossiers vus par l'évaluateur — `GET /api/evaluateur/candidatures`.
 * L'AUTO-SCOPE par rôle (mes dossiers vs tous les dossiers) est fait par le
 * backend ; ce hook ne fait que passer les filtres et exposer la réponse telle
 * quelle (pagination Laravel : `data` + `meta`).
 */
export function useDossiers({ statutInterne = '', filiere = '', page = 1 } = {}) {
  const [state, setState] = useState({ status: 'loading', items: [], meta: null })

  useEffect(() => {
    let alive = true
    // oxlint-disable-next-line react/set-state-in-effect
    setState((s) => ({ ...s, status: 'loading' }))

    const qs = new URLSearchParams()
    if (statutInterne) qs.set('statut_interne', statutInterne)
    if (filiere) qs.set('filiere', filiere)
    if (page > 1) qs.set('page', String(page))
    const suffix = qs.toString() ? `?${qs.toString()}` : ''

    apiClient
      .get(`/evaluateur/candidatures${suffix}`)
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
  }, [statutInterne, filiere, page])

  return state
}
