import { useCallback, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

/**
 * Remplacement d'un candidat retenu indisponible (Lot 8d-3, post-publication)
 * — `POST /admin/remplacements`. La réponse `{indisponible, promu}` est
 * l'unique source de vérité du résultat RÉEL (le promu affiché AVANT
 * confirmation n'est qu'un aperçu, cf. `Classement.jsx`).
 */
export function useRemplacement() {
  const [replacing, setReplacing] = useState(false)
  const [error, setError] = useState(null)

  const remplacer = useCallback(async (candidatureId, motif) => {
    setReplacing(true)
    setError(null)
    try {
      const res = await apiClient.post('/admin/remplacements', { candidature_id: candidatureId, motif })
      return res.data
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Le remplacement a échoué.')
      throw err
    } finally {
      setReplacing(false)
    }
  }, [])

  return { remplacer, replacing, error, resetError: () => setError(null) }
}
