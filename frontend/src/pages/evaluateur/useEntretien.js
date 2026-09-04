import { useCallback, useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

/**
 * Volet ENTRETIEN /35 (Lot 8c-2) —
 * `GET/PUT /evaluateur/candidatures/{id}/entretien` + `POST .../entretien/validation`.
 *
 * Même règle reine que le dossier (ADR-02, ADR-04) : `entretien` (planification,
 * présence, sous-notes, score) vient intégralement de la réponse serveur à
 * chaque appel — jamais recalculé ici. `dossierVerrouille` est la précondition
 * (Lot 4b) reflétée telle quelle.
 */
export function useEntretien(id) {
  const [state, setState] = useState({ status: 'loading', dossierVerrouille: false, entretien: null })
  const [saving, setSaving] = useState(false)
  const [validating, setValidating] = useState(false)
  const [error, setError] = useState(null)

  const applyResponse = (data) =>
    setState({ status: 'ready', dossierVerrouille: !!data.dossier_verrouille, entretien: data.entretien })

  const load = useCallback(() => {
    let cancelled = false
    setState((s) => ({ ...s, status: 'loading' }))

    apiClient
      .get(`/evaluateur/candidatures/${id}/entretien`)
      .then((res) => {
        if (cancelled) return
        applyResponse(res.data)
      })
      .catch(() => {
        if (cancelled) return
        setState({ status: 'error', dossierVerrouille: false, entretien: null })
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
        const res = await apiClient.put(`/evaluateur/candidatures/${id}/entretien`, patch)
        applyResponse(res.data)
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
      const res = await apiClient.post(`/evaluateur/candidatures/${id}/entretien/validation`)
      applyResponse(res.data)
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
