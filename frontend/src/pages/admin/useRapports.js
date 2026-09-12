import { useCallback, useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'

/**
 * Rapports & statistiques — `GET /api/admin/rapports?campagne=` (Lot 11c).
 *
 * L'écran NE CALCULE RIEN : il affiche les agrégats renvoyés par
 * `ServiceRapports` (comptes, distributions, taux), déjà passés au garde-fou
 * k-anonymat côté serveur — une distribution masquée arrive `null`, un taux
 * masqué arrive `null`, les villes rares sont déjà fondues dans « Autres ».
 * Aucune borne de barème dans ce code : les tranches de score viennent du
 * payload (`distribution_scores.bornes`).
 *
 * `campagne` : `''` = campagne courante (défaut serveur), `'toutes'` = agrégat
 * global, un id = cette campagne. La liste des campagnes alimente le sélecteur.
 */
export function useRapports() {
  const [campagne, setCampagne] = useState('')
  const [state, setState] = useState({ status: 'loading', data: null })
  const [campagnes, setCampagnes] = useState([])

  useEffect(() => {
    apiClient.get('/admin/campagnes').then((res) => setCampagnes(res.data ?? [])).catch(() => {})
  }, [])

  useEffect(() => {
    let alive = true
    // oxlint-disable-next-line react/set-state-in-effect -- transition d'état de chargement avant un fetch (synchro avec la campagne choisie)
    setState((s) => ({ ...s, status: s.data ? 'refreshing' : 'loading' }))

    const suffix = campagne ? `?campagne=${encodeURIComponent(campagne)}` : ''
    apiClient
      .get(`/admin/rapports${suffix}`)
      .then((res) => {
        if (alive) setState({ status: 'ready', data: res.data })
      })
      .catch(() => {
        if (alive) setState({ status: 'error', data: null })
      })

    return () => {
      alive = false
    }
  }, [campagne])

  const [exporting, setExporting] = useState(false)

  const telecharger = useCallback(async (chemin) => {
    setExporting(true)
    try {
      const suffix = campagne ? `?campagne=${encodeURIComponent(campagne)}` : ''
      const { blob, filename } = await apiClient.getBlob(`${chemin}${suffix}`)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = filename
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
    } finally {
      setExporting(false)
    }
  }, [campagne])

  const telechargerCsv = useCallback(() => telecharger('/admin/rapports/export.csv'), [telecharger])
  // Lot 15c — même service d'agrégation que le CSV (ServiceRapports), juste un
  // autre format de présentation ; PDF reste "à venir" (pas construit ce lot).
  const telechargerXlsx = useCallback(() => telecharger('/admin/rapports/export.xlsx'), [telecharger])

  return { ...state, campagne, setCampagne, campagnes, telechargerCsv, telechargerXlsx, exporting }
}
