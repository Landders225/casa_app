import { useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'

async function page(query) {
  const res = await apiClient.get(`/admin/candidatures${query}`)
  return { total: res.meta?.total ?? 0 }
}

/**
 * Tableau de bord administrateur (Lot 8d-1) — même patron que
 * `useDashboard.js` évaluateur (8c-1) : 4 appels `GET /admin/candidatures`
 * filtrés, dont on ne lit que `meta.total` (aucun comptage reconstitué côté
 * client). `nonAffectes` utilise `?evaluateur=non_affecte`, un filtre déjà
 * supporté par le backend. Pas de Chart.js, pas de taux calculés (éligibilité/
 * sélection, non dérivables des filtres exposés — même simplification qu'au
 * 8c-1, D-8c1-1/2).
 */
export function useDashboardAdmin() {
  const [state, setState] = useState({ status: 'loading' })

  useEffect(() => {
    let alive = true

    Promise.all([
      page(''),
      page('?evaluateur=non_affecte'),
      page('?statut_interne=en_instruction'),
      page('?statut_interne=evalue'),
      apiClient.get('/admin/campagnes'),
    ])
      .then(([tous, nonAffectes, enInstruction, evalues, campagnes]) => {
        if (!alive) return
        setState({
          status: 'ready',
          kpis: {
            total: tous.total,
            nonAffectes: nonAffectes.total,
            enInstruction: enInstruction.total,
            evalues: evalues.total,
          },
          campagnes: campagnes.data ?? [],
        })
      })
      .catch(() => {
        if (alive) setState({ status: 'error' })
      })

    return () => {
      alive = false
    }
  }, [])

  return state
}
