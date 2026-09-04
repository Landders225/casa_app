import { useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

/**
 * État de MA candidature pour les écrans de suivi / résultat (Lot 8b-3).
 *
 * Consomme `GET /api/candidature` et n'expose QUE des primitives d'affichage,
 * copiées telles quelles de la réponse — AUCUNE dérivation (ADR-03, règle reine).
 * `statut_public` / `decision` / `motif_communicable` sont déjà résolus côté
 * serveur par `StatutPublicResolver` ; `reponses` / `experiences` / `classement`
 * ne sont volontairement PAS remontés (le suivi n'en a pas besoin).
 *
 * `status` : 'loading' | 'none' (404, aucune candidature) | 'ready' | 'error'
 */
const EMPTY = {
  status: 'loading',
  statutPublic: null,
  decision: null,
  motifCommunicable: null,
  numeroDossier: null,
  dateSoumission: null,
  filiereNom: null,
  campagneNom: null,
  piecesCount: 0,
}

export function useMaCandidature() {
  const [state, setState] = useState(EMPTY)

  useEffect(() => {
    let alive = true

    apiClient
      .get('/candidature')
      .then((res) => {
        if (!alive) return
        const d = res.data ?? {}
        setState({
          status: 'ready',
          statutPublic: d.statut_public ?? null,
          decision: d.decision ?? null,
          motifCommunicable: d.motif_communicable ?? null,
          numeroDossier: d.numero_dossier ?? null,
          dateSoumission: d.date_soumission ?? null,
          filiereNom: d.filiere?.nom ?? null,
          campagneNom: d.campagne?.nom ?? null,
          piecesCount: Array.isArray(d.pieces_dossier) ? d.pieces_dossier.length : 0,
        })
      })
      .catch((err) => {
        if (!alive) return
        if (err instanceof ApiError && err.status === 404) {
          setState({ ...EMPTY, status: 'none' })
        } else {
          setState({ ...EMPTY, status: 'error' })
        }
      })

    return () => {
      alive = false
    }
  }, [])

  return state
}
