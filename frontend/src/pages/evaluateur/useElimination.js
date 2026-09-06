import { useCallback, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

/**
 * Élimination manuelle (Lot 8d-3) — `POST /admin/candidatures/{id}/elimination`.
 * DUPLIQUÉ de `pages/admin/useElimination.js` (même contenu) : l'élimination
 * s'ouvre à la fois depuis `CandidaturesSupervision.jsx` (`pages/admin/`) et
 * `FicheCandidat.jsx` (`pages/evaluateur/`) — même raison que
 * `MotifConfirmDialog.jsx`, pas de pont entre les deux arbres.
 *
 * Mono-cible UNIQUEMENT (D-6b-4) : le backend n'a pas d'endpoint bulk.
 */
export function useElimination() {
  const [eliminating, setEliminating] = useState(false)
  const [error, setError] = useState(null)

  const eliminer = useCallback(async (candidatureId, motif) => {
    setEliminating(true)
    setError(null)
    try {
      const res = await apiClient.post(`/admin/candidatures/${candidatureId}/elimination`, { motif })
      return res.data
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "L'élimination a échoué.")
      throw err
    } finally {
      setEliminating(false)
    }
  }, [])

  return { eliminer, eliminating, error, resetError: () => setError(null) }
}
