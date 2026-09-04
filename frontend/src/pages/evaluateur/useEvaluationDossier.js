import { useCallback, useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

/**
 * Notation du volet DOSSIER /65 (Lot 8c-2) —
 * `GET/PUT /evaluateur/candidatures/{id}/evaluation` + `POST .../evaluation/validation`.
 *
 * Règle reine (ADR-02, ADR-04) : le score AFFICHÉ vient TOUJOURS de la réponse
 * serveur — un aperçu recalculé sur la grille active tant que non verrouillé,
 * un snapshot figé après validation. Ce hook ne fait QUE remplacer son état
 * par la réponse brute de chaque appel ; aucun total ni pourcentage de
 * rubrique n'est recalculé ici.
 */
export function useEvaluationDossier(id) {
  const [state, setState] = useState({ status: 'loading', evaluation: null })
  const [saving, setSaving] = useState(false)
  const [validating, setValidating] = useState(false)
  const [error, setError] = useState(null)

  const load = useCallback(() => {
    let cancelled = false
    setState((s) => ({ ...s, status: 'loading' }))

    apiClient
      .get(`/evaluateur/candidatures/${id}/evaluation`)
      .then((res) => {
        if (cancelled) return
        setState({ status: 'ready', evaluation: res.data })
      })
      .catch(() => {
        if (cancelled) return
        setState({ status: 'error', evaluation: null })
      })

    return () => {
      cancelled = true
    }
  }, [id])

  // oxlint-disable-next-line react/set-state-in-effect
  useEffect(() => load(), [load])

  const save = useCallback(
    async (patch) => {
      setSaving(true)
      setError(null)
      try {
        const res = await apiClient.put(`/evaluateur/candidatures/${id}/evaluation`, patch)
        setState({ status: 'ready', evaluation: res.data })
        return res.data
      } catch (err) {
        setError(err instanceof ApiError ? err.message : "L'enregistrement a échoué.")
        throw err
      } finally {
        setSaving(false)
      }
    },
    [id],
  )

  const validate = useCallback(async () => {
    setValidating(true)
    setError(null)
    try {
      const res = await apiClient.post(`/evaluateur/candidatures/${id}/evaluation/validation`)
      setState({ status: 'ready', evaluation: res.data })
      return res.data
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'La validation a échoué.')
      throw err
    } finally {
      setValidating(false)
    }
  }, [id])

  return { ...state, saving, validating, error, save, validate, reload: load }
}
