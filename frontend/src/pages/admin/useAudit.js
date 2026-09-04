import { useCallback, useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'

/**
 * Journal d'audit — `GET /api/admin/audit` (Lot 6a). Le front ne fait que
 * relayer les filtres vers le serveur, JAMAIS de filtrage client sur
 * `ancienne_valeur`/`nouvelle_valeur`/`motif` (🔴) — `recherche` reste borné à
 * `action`+`objet` côté serveur (docstring `AuditController`) ; ce hook ne
 * fait qu'envoyer ce que l'utilisateur a tapé, il ne cherche rien lui-même.
 */
export function useAudit({ module = '', auteur = '', dateDebut = '', dateFin = '', recherche = '', page = 1 } = {}) {
  const [state, setState] = useState({ status: 'loading', items: [], meta: null })

  const load = useCallback(() => {
    let alive = true
    setState((s) => ({ ...s, status: 'loading' }))

    const qs = new URLSearchParams()
    if (module) qs.set('module', module)
    if (auteur) qs.set('auteur', auteur)
    if (dateDebut) qs.set('date_debut', dateDebut)
    if (dateFin) qs.set('date_fin', dateFin)
    if (recherche) qs.set('recherche', recherche)
    if (page > 1) qs.set('page', String(page))
    const suffix = qs.toString() ? `?${qs.toString()}` : ''

    apiClient
      .get(`/admin/audit${suffix}`)
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
  }, [module, auteur, dateDebut, dateFin, recherche, page])

  // oxlint-disable-next-line react/set-state-in-effect
  useEffect(() => load(), [load])

  return { ...state, reload: load }
}
