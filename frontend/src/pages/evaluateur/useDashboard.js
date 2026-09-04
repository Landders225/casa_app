import { useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'

async function page(query) {
  const res = await apiClient.get(`/evaluateur/candidatures${query}`)
  return { total: res.meta?.total ?? 0, items: res.data ?? [] }
}

/**
 * Tableau de bord évaluateur — 4 appels `GET /evaluateur/candidatures` filtrés
 * par `statut_interne`, dont on ne lit que `meta.total` pour les KPI (aucun
 * comptage reconstitué côté client) et les items de la 1re page pour les deux
 * mini-listes. Les « alertes d'éligibilité » filtrent `statut_eligibilite_interne`
 * — un champ DÉJÀ calculé par le serveur ; ce n'est pas un recalcul.
 */
export function useDashboard() {
  const [state, setState] = useState({ status: 'loading' })

  useEffect(() => {
    let alive = true

    Promise.all([
      page(''),
      page('?statut_interne=soumis'),
      page('?statut_interne=en_instruction'),
      page('?statut_interne=evalue'),
    ])
      .then(([tous, soumis, enInstruction, evalues]) => {
        if (!alive) return
        setState({
          status: 'ready',
          kpis: {
            total: tous.total,
            aTraiter: soumis.total,
            enInstruction: enInstruction.total,
            evalues: evalues.total,
          },
          prioritaires: soumis.items.slice(0, 5),
          alertes: enInstruction.items
            .filter((c) => c.statut_eligibilite_interne === 'non_eligible')
            .slice(0, 5),
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
