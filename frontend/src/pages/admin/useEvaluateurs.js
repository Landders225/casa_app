import { useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'

/**
 * Liste des évaluateurs — `GET /api/admin/evaluateurs` (Lot 8d-1, nouveau).
 * Alimente le sélecteur d'affectation et le filtre `?evaluateur=` de la vue
 * supervision. Liste blanche stricte côté serveur (`EvaluateurResource`) :
 * jamais l'e-mail.
 */
export function useEvaluateurs() {
  const [state, setState] = useState({ status: 'loading', items: [] })

  useEffect(() => {
    let alive = true
    apiClient
      .get('/admin/evaluateurs')
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

  return state
}
