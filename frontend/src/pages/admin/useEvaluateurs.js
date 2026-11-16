import { useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'

/**
 * Liste des évaluateurs — `GET /api/admin/evaluateurs` (Lot 8d-1, nouveau).
 * Alimente le sélecteur d'affectation/réattribution (par défaut : actifs
 * seulement — jamais une cible désactivée) et, avec `{ tous: true }`
 * (Lot 20b), le filtre `?evaluateur=` de la vue supervision, qui doit AUSSI
 * proposer les évaluateurs désactivés (mention « désactivé », `actif`
 * exposé par la Resource) sans jamais les offrir comme cible. Liste blanche
 * stricte côté serveur (`EvaluateurResource`) : jamais l'e-mail.
 */
export function useEvaluateurs({ tous = false } = {}) {
  const [state, setState] = useState({ status: 'loading', items: [] })

  useEffect(() => {
    let alive = true
    apiClient
      .get(tous ? '/admin/evaluateurs?tous=1' : '/admin/evaluateurs')
      .then((res) => {
        if (alive) setState({ status: 'ready', items: res.data ?? [] })
      })
      .catch(() => {
        if (alive) setState({ status: 'error', items: [] })
      })
    return () => {
      alive = false
    }
  }, [tous])

  return state
}
