import { useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

/**
 * État de l'écran Documents (Lot 14) — `GET /api/candidature`, comme
 * `useMaCandidature`, mais expose EN PLUS `piecesDossier` et `experiences`
 * (volontairement omis par `useMaCandidature`, qui n'en a pas besoin pour le
 * suivi). Un seul appel réseau : le contrôleur charge déjà
 * `experiences.pieceJustificative` + `piecesDossier` (Étape 1, Q1) — pas besoin
 * de `GET /candidatures/{id}/pieces` en plus.
 *
 * Copie telle quelle la réponse serveur, aucune dérivation (ADR-03) : les
 * métadonnées de pièce (`PieceJustificativeResource`) sont déjà en liste
 * blanche (jamais `chemin_stockage`), l'URL de téléchargement est déjà
 * fournie par le serveur (`piece.url`), jamais construite ici.
 *
 * `status` : 'loading' | 'none' (404, aucune candidature) | 'ready' | 'error'
 */
const EMPTY = {
  status: 'loading',
  statutPublic: null,
  numeroDossier: null,
  piecesDossier: [],
  experiences: [],
}

export function useDocuments() {
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
          numeroDossier: d.numero_dossier ?? null,
          piecesDossier: Array.isArray(d.pieces_dossier) ? d.pieces_dossier : [],
          experiences: Array.isArray(d.experiences) ? d.experiences : [],
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
