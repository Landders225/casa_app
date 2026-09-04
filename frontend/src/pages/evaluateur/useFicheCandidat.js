import { useCallback, useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

/**
 * Fiche candidat vue par l'évaluateur — `GET /api/evaluateur/candidatures/{id}`
 * + `PUT .../verification`.
 *
 * Règle reine (inversée ici, cf. Lot 8c-1) : la réponse du PUT est la
 * `CandidatureEvaluateurResource` FRAÎCHE — `criteres_eliminatoires` et
 * `statut_eligibilite_interne` déjà recalculés SERVEUR. On remplace l'état local
 * par cette réponse telle quelle ; on ne recalcule jamais rien ici.
 */
export function useFicheCandidat(id) {
  const [state, setState] = useState({ status: 'loading', dossier: null })
  const [saving, setSaving] = useState(false)
  const [verifError, setVerifError] = useState(null)

  const load = useCallback(() => {
    let cancelled = false
    setState((s) => ({ ...s, status: 'loading' }))

    apiClient
      .get(`/evaluateur/candidatures/${id}`)
      .then((res) => {
        if (cancelled) return
        setState({ status: 'ready', dossier: res.data })
      })
      .catch((err) => {
        if (cancelled) return
        const notFound = err instanceof ApiError && err.status === 404
        setState({ status: notFound ? 'not_found' : 'error', dossier: null })
      })

    return () => {
      cancelled = true
    }
  }, [id])

  // oxlint-disable-next-line react/set-state-in-effect
  useEffect(() => load(), [load])

  const updateVerification = useCallback(
    async (patch) => {
      setSaving(true)
      setVerifError(null)
      try {
        const res = await apiClient.put(`/evaluateur/candidatures/${id}/verification`, patch)
        // Remplacement intégral par la ressource fraîche : le panneau
        // Éligibilité se re-rend depuis des données 100% serveur.
        setState({ status: 'ready', dossier: res.data })
        return res.data
      } catch (err) {
        setVerifError(err instanceof ApiError ? err.message : 'La vérification a échoué.')
        throw err
      } finally {
        setSaving(false)
      }
    },
    [id],
  )

  return { ...state, saving, verifError, updateVerification, reload: load }
}
