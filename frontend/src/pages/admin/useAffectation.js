import { useCallback, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

/**
 * Affectation en masse — `POST /api/admin/affectations` (Lot 6a). ATOMIQUE
 * côté serveur : si une seule candidature n'est pas affectable, RIEN n'est
 * écrit et le backend renvoie 422 avec la liste des refusées dans
 * `err.errors.candidature_ids` (un message déjà entièrement rédigé,
 * numéros de dossier inclus) — ce hook l'expose TEL QUEL, ne le reformule pas.
 */
export function useAffectation() {
  const [assigning, setAssigning] = useState(false)
  const [error, setError] = useState(null)

  const assign = useCallback(async (evaluateurId, candidatureIds) => {
    setAssigning(true)
    setError(null)
    try {
      const res = await apiClient.post('/admin/affectations', {
        evaluateur_id: evaluateurId,
        candidature_ids: candidatureIds,
      })
      return res.data
    } catch (err) {
      const message = err instanceof ApiError
        ? (err.validationMessages[0] || err.message)
        : "L'affectation a échoué."
      setError(message)
      throw err
    } finally {
      setAssigning(false)
    }
  }, [])

  return { assign, assigning, error, resetError: () => setError(null) }
}
