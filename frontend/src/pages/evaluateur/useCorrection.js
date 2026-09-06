import { useCallback, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

/**
 * Correction exceptionnelle (Lot 8d-3) — SEULE opération qui rouvre une
 * évaluation/un entretien verrouillé (ADR-04) —
 * `POST /admin/candidatures/{id}/correction/dossier|entretien`.
 *
 * La réponse a une forme DIFFÉRENTE d'un `EvaluationDossierResource`/
 * `EntretienResource` (`{numero_dossier, score_dossier|score_entretien,
 * statut_eligibilite_interne?, champs_modifies}`, pas de `rubriques`/`lignes`) :
 * ce hook ne l'injecte donc PAS dans un état de notation — l'appelant doit
 * recharger `useEvaluationDossier`/`useEntretien` (et `useFicheCandidat` pour
 * le dossier, l'éligibilité ayant pu changer) après succès, même patron que
 * la publication au 8d-2.
 */
export function useCorrection() {
  const [correcting, setCorrecting] = useState(false)
  const [error, setError] = useState(null)

  const corrigerDossier = useCallback(async (candidatureId, payload) => {
    setCorrecting(true)
    setError(null)
    try {
      const res = await apiClient.post(`/admin/candidatures/${candidatureId}/correction/dossier`, payload)
      return res.data
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'La correction a échoué.')
      throw err
    } finally {
      setCorrecting(false)
    }
  }, [])

  const corrigerEntretien = useCallback(async (candidatureId, payload) => {
    setCorrecting(true)
    setError(null)
    try {
      const res = await apiClient.post(`/admin/candidatures/${candidatureId}/correction/entretien`, payload)
      return res.data
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'La correction a échoué.')
      throw err
    } finally {
      setCorrecting(false)
    }
  }, [])

  return { corrigerDossier, corrigerEntretien, correcting, error, resetError: () => setError(null) }
}
