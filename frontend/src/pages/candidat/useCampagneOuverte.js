import { useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'

/**
 * Signal minimal « une campagne est-elle ouverte ? » (`GET /campagne-ouverte`,
 * ADR-42) — sert UNIQUEMENT à éviter d'afficher un bouton qui échouerait à
 * coup sûr ; la vraie barrière reste serveur (`POST /candidatures`, 409
 * sinon). `null` pendant le chargement ET en cas d'échec — volontairement
 * fail-open : un appelant doit traiter `null` comme « ouverte » (ne jamais
 * masquer un bouton légitime à tort pour un simple signal secondaire).
 */
export function useCampagneOuverte() {
  const [ouverte, setOuverte] = useState(null)

  useEffect(() => {
    let alive = true
    apiClient
      .get('/campagne-ouverte')
      .then((res) => {
        if (alive) setOuverte(Boolean(res.data?.campagne_ouverte))
      })
      .catch(() => {
        // best-effort — jamais d'erreur affichée pour ce signal secondaire.
      })
    return () => {
      alive = false
    }
  }, [])

  return ouverte
}
