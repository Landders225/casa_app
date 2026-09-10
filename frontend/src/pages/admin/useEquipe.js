import { useCallback, useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'

/**
 * Comptes de l'équipe (Lot 11b) — `GET/POST /api/admin/membres`,
 * `PATCH /api/admin/membres/{id}`, `POST /api/admin/membres/{id}/mot-de-passe`.
 *
 * Le hook ne devine RIEN : les garde-fous (G1 auto-désactivation, G2 dernier
 * admin actif) et la validation du rôle sont 100 % serveur — un 422 remonte
 * VERBATIM à l'écran. Chaque mutation remplace la ligne concernée par la
 * réponse serveur (ou recharge, pour la création).
 *
 * Le mot de passe temporaire renvoyé par la création et la réinitialisation
 * n'est PAS stocké dans l'état de liste : il transite une seule fois vers le
 * composant qui l'affiche, puis il est perdu.
 */
export function useEquipe() {
  const [state, setState] = useState({ status: 'loading', items: [] })
  const [busyId, setBusyId] = useState(null) // id du membre en cours de mutation
  const [creating, setCreating] = useState(false)

  const load = useCallback(() => {
    let alive = true
    setState((s) => ({ ...s, status: s.items.length ? s.status : 'loading' }))
    apiClient
      .get('/admin/membres')
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

  const remplacerLigne = (membre) =>
    setState((s) => ({ ...s, items: s.items.map((m) => (m.id === membre.id ? { ...m, ...membre } : m)) }))

  /** POST — renvoie `{ membre, motDePasse }` ; lève l'ApiError (422 rôle, e-mail pris…). */
  const creer = useCallback(async (payload) => {
    setCreating(true)
    try {
      const res = await apiClient.post('/admin/membres', payload)
      await Promise.resolve(load())
      return { membre: res.data, motDePasse: res.mot_de_passe_temporaire }
    } finally {
      setCreating(false)
    }
  }, [load])

  /** PATCH { actif } — lève l'ApiError (422 G1/G2) ; le message est à afficher verbatim. */
  const definirActivation = useCallback(async (id, actif) => {
    setBusyId(id)
    try {
      const res = await apiClient.patch(`/admin/membres/${id}`, { actif })
      remplacerLigne(res.data)
      return res.data
    } finally {
      setBusyId(null)
    }
  }, [])

  /** POST .../mot-de-passe — renvoie le nouveau mot de passe temporaire (affichage unique). */
  const reinitialiserMotDePasse = useCallback(async (id) => {
    setBusyId(id)
    try {
      const res = await apiClient.post(`/admin/membres/${id}/mot-de-passe`)
      return res.mot_de_passe_temporaire
    } finally {
      setBusyId(null)
    }
  }, [])

  return { ...state, busyId, creating, creer, definirActivation, reinitialiserMotDePasse, reload: load }
}
