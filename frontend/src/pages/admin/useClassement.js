import { useCallback, useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

/**
 * Classement & décisions d'une campagne (Lot 8d-2) —
 * `GET/POST /admin/campagnes/{id}/classement`, `POST .../publier`,
 * `PUT /admin/candidatures/{id}/decision/motifs`.
 *
 * Règle reine (ADR-02/04) : `score_final`, `rang` et `departage` viennent
 * TOUJOURS de la réponse serveur — ce hook remplace intégralement son état par
 * chaque réponse, sans jamais trier ni recalculer les lignes.
 *
 * `POST .../publier` renvoie un `PublicationResource` (forme différente d'un
 * `ClassementResource` : pas de `filieres`) — on ne l'injecte donc PAS
 * directement dans l'état ; on recharge `GET .../classement` ensuite, ce qui
 * renvoie `publie:true` + `publiee_le`/`publiee_par` (Étape 1 Q3), exactement
 * ce qu'un rechargement de page donnerait.
 */
export function useClassement(campagneId) {
  const [state, setState] = useState({ status: 'loading', data: null })
  const [calculating, setCalculating] = useState(false)
  const [publishing, setPublishing] = useState(false)
  const [savingMotif, setSavingMotif] = useState(false)
  const [error, setError] = useState(null)

  const load = useCallback(() => {
    // Pas encore de campagne dans l'URL (redirection vers la plus récente en
    // cours, cf. Classement.jsx) : rien à charger.
    if (!campagneId) {
      setState({ status: 'idle', data: null })
      return undefined
    }

    let alive = true
    setState((s) => ({ ...s, status: 'loading' }))
    apiClient
      .get(`/admin/campagnes/${campagneId}/classement`)
      .then((res) => {
        if (alive) setState({ status: 'ready', data: res.data })
      })
      .catch(() => {
        if (alive) setState({ status: 'error', data: null })
      })
    return () => {
      alive = false
    }
  }, [campagneId])

  // oxlint-disable-next-line react/set-state-in-effect
  useEffect(() => load(), [load])

  const calculer = useCallback(async () => {
    setCalculating(true)
    setError(null)
    try {
      const res = await apiClient.post(`/admin/campagnes/${campagneId}/classement`)
      setState({ status: 'ready', data: res.data })
      return res.data
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Le calcul du classement a échoué.')
      throw err
    } finally {
      setCalculating(false)
    }
  }, [campagneId])

  const publier = useCallback(async () => {
    setPublishing(true)
    setError(null)
    try {
      await apiClient.post(`/admin/campagnes/${campagneId}/publier`)
      const res = await apiClient.get(`/admin/campagnes/${campagneId}/classement`)
      setState({ status: 'ready', data: res.data })
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'La publication a échoué.')
      throw err
    } finally {
      setPublishing(false)
    }
  }, [campagneId])

  const enregistrerMotifs = useCallback(async (candidatureId, motifs) => {
    setSavingMotif(true)
    setError(null)
    try {
      const res = await apiClient.put(`/admin/candidatures/${candidatureId}/decision/motifs`, motifs)
      setState({ status: 'ready', data: res.data })
      return res.data
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "L'enregistrement du motif a échoué.")
      throw err
    } finally {
      setSavingMotif(false)
    }
  }, [])

  return { ...state, calculating, publishing, savingMotif, error, calculer, publier, enregistrerMotifs, reload: load }
}
