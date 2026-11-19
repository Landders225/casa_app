import { useCallback, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

// Miroir de RetablissementEligibiliteController::PLAFOND_DOSSIERS (ADR-49).
const PLAFOND_RETABLISSEMENT = 500

/**
 * Lot 4 (ADR-49) — fonction PURE, co-localisée avec le hook plutôt que dans
 * `CandidaturesSupervision.jsx` (même patron que `criteresSupervision`,
 * co-localisée avec `useCandidaturesAdmin` — évite aussi l'avertissement
 * oxlint react(only-export-components) sur un fichier de composant). Extraite
 * pour être testée directement : le cas « plafond dépassé » demanderait 501
 * cases cochées une à une en test UI, irréaliste ; ce calcul EN DIRECT, lui,
 * ne l'est pas.
 */
export function calculerRetablissementDesactive({ selectionTousActif, contientDejaEligible, taille, plafond = PLAFOND_RETABLISSEMENT }) {
  if (selectionTousActif) {
    return { desactive: true, titre: 'Désactivé en mode « tous les N résultats » : choisissez les dossiers un par un.' }
  }
  if (contientDejaEligible) {
    return { desactive: true, titre: 'Au moins un dossier sélectionné est déjà éligible.' }
  }
  if (taille > plafond) {
    return { desactive: true, titre: `Au-delà de ${plafond} dossiers, affinez votre sélection.` }
  }
  return { desactive: false, titre: undefined }
}

/**
 * Rétablissement d'éligibilité (Lot 4, ADR-49) —
 * `POST /api/admin/candidatures/eligibilite/retablir`. ATOMIQUE côté serveur,
 * même patron qu'`useReattribution.js` : le message (422 déjà éligible/brouillon,
 * 409 campagne publiée) est exposé TEL QUEL, jamais reformulé.
 */
export function useRetablissementEligibilite() {
  const [retablissant, setRetablissant] = useState(false)
  const [error, setError] = useState(null)

  const retablir = useCallback(async (candidatureIds, motif) => {
    setRetablissant(true)
    setError(null)
    try {
      const res = await apiClient.post('/admin/candidatures/eligibilite/retablir', {
        candidature_ids: candidatureIds,
        motif,
      })
      return res.data
    } catch (err) {
      const message = err instanceof ApiError
        ? (err.validationMessages[0] || err.message)
        : "Le rétablissement d'éligibilité a échoué."
      setError(message)
      throw err
    } finally {
      setRetablissant(false)
    }
  }, [])

  return { retablir, retablissant, error, resetError: () => setError(null) }
}
