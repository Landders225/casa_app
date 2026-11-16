import { useCallback, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

/**
 * Réattribution de dossiers DÉJÀ AFFECTÉS — `POST /api/admin/reattributions`
 * (Lot 20b). `evaluateurId` peut être `null` (remettre dans le lot non
 * affecté) — refusé par le serveur si un dossier du lot est déjà verrouillé.
 * ATOMIQUE côté serveur, même patron qu'`useAffectation.js` : le message
 * (422/409) est exposé TEL QUEL, jamais reformulé.
 */
export function useReattribution() {
  const [reattributing, setReattributing] = useState(false)
  const [error, setError] = useState(null)

  const reattribuer = useCallback(async (evaluateurId, candidatureIds) => {
    setReattributing(true)
    setError(null)
    try {
      const res = await apiClient.post('/admin/reattributions', {
        evaluateur_id: evaluateurId,
        candidature_ids: candidatureIds,
      })
      return res.data
    } catch (err) {
      const message = err instanceof ApiError
        ? (err.validationMessages[0] || err.message)
        : 'La réattribution a échoué.'
      setError(message)
      throw err
    } finally {
      setReattributing(false)
    }
  }, [])

  return { reattribuer, reattributing, error, resetError: () => setError(null) }
}
