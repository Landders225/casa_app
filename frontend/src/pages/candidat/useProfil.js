import { useCallback, useEffect, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'

/**
 * Mon profil (Lot 13) — `GET`/`PATCH /api/candidat/profil` (Lot 7, ADR-16).
 *
 * Le hook ne devine rien : les champs interdits (`email`, `residence_ci`,
 * nationalité, diplôme) ne sont JAMAIS envoyés par ce hook — l'écran ne les
 * propose même pas (ADR-07). Toute erreur 422 (dont le garde-fou d'âge sur
 * `date_naissance`) remonte telle quelle à l'appelant.
 */
export function useProfil() {
  const [state, setState] = useState({ status: 'loading', profil: null })
  const [saving, setSaving] = useState(false)

  const charger = useCallback(() => {
    let alive = true
    apiClient
      .get('/candidat/profil')
      .then((res) => {
        if (alive) setState({ status: 'ready', profil: res.data })
      })
      .catch(() => {
        if (alive) setState({ status: 'error', profil: null })
      })
    return () => {
      alive = false
    }
  }, [])

  // oxlint-disable-next-line react/set-state-in-effect
  useEffect(() => charger(), [charger])

  /** PATCH partiel — ne lève jamais localement, propage l'ApiError (422 champ par champ). */
  const enregistrer = useCallback(async (patch) => {
    setSaving(true)
    try {
      const res = await apiClient.patch('/candidat/profil', patch)
      setState({ status: 'ready', profil: res.data })
      return res.data
    } finally {
      setSaving(false)
    }
  }, [])

  return { ...state, saving, enregistrer, recharger: charger }
}
