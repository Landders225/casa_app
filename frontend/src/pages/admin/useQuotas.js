import { useCallback, useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

/**
 * Écran Quotas (Lot 17, D-6a-2) — `GET /api/admin/campagnes` (étendu :
 * filieres/quota + `classement_calcule`/`classement_perime`/`publiee`) +
 * les 3 écritures nouvelles. AUCUNE règle de garde-fou n'est devinée ici —
 * le hook ne fait que passer les corps de requête et remplacer la ligne
 * concernée par la réponse serveur (même patron que `useCampagnes.js`,
 * délibérément un hook SÉPARÉ : deux natures d'action différentes, Étape 1 Q5).
 */
export function useQuotas() {
  const [state, setState] = useState({ status: 'loading', items: [] })
  const [saving, setSaving] = useState(null) // id de la campagne en cours d'écriture
  const [creating, setCreating] = useState(false)
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

  const remplacer = (campagne) => {
    setState((s) => ({ ...s, items: s.items.map((c) => (c.id === campagne.id ? campagne : c)) }))
  }

  const creer = useCallback(async (payload) => {
    setCreating(true)
    setError(null)
    try {
      const res = await apiClient.post('/admin/campagnes', payload)
      setState((s) => ({ ...s, items: [res.data, ...s.items] }))
      return res.data
    } finally {
      setCreating(false)
    }
  }, [])

  const modifierInfos = useCallback(async (id, payload) => {
    setSaving(id)
    setError(null)
    try {
      const res = await apiClient.put(`/admin/campagnes/${id}`, payload)
      remplacer(res.data)
      return res.data
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'La modification a échoué.')
      throw err
    } finally {
      setSaving(null)
    }
  }, [])

  const modifierQuotas = useCallback(async (id, quotas) => {
    setSaving(id)
    setError(null)
    try {
      const res = await apiClient.put(`/admin/campagnes/${id}/quotas`, { quotas })
      remplacer(res.data)
      return res.data
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'La modification des quotas a échoué.')
      throw err
    } finally {
      setSaving(null)
    }
  }, [])

  return { ...state, saving, creating, error, creer, modifierInfos, modifierQuotas, reload: load }
}
