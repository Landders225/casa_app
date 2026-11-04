import { useCallback, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

function messageErreur(err, defaut) {
  if (!(err instanceof ApiError)) return defaut
  return err.validationMessages[0] || err.message || defaut
}

/**
 * Affectation automatique par critères (ADR-45) — aperçu puis exécution.
 *
 *   POST /admin/affectations/automatique/apercu  { criteres, evaluateur_ids?, nombre_correspondants }
 *   POST /admin/affectations/automatique         { …, nombre_affectables }
 *
 * Aucune liste d'identifiants : le serveur recalcule l'ensemble à partir des
 * critères et refuse (409 `liste_modifiee`) si ses nombres diffèrent de ceux
 * que l'administrateur a vus. Les messages du serveur sont affichés TELS
 * QUELS ; `code` (`err.payload.code`) est exposé pour adapter l'action proposée.
 */
export function useAffectationAutomatique() {
  const [apercu, setApercu] = useState(null)
  const [chargement, setChargement] = useState(false)
  const [execution, setExecution] = useState(false)
  const [erreur, setErreur] = useState(null)

  const calculerApercu = useCallback(async ({ criteres, nombreCorrespondants, evaluateurIds }) => {
    setChargement(true)
    setErreur(null)
    setApercu(null)
    try {
      const body = { criteres, nombre_correspondants: nombreCorrespondants }
      if (evaluateurIds) body.evaluateur_ids = evaluateurIds
      const res = await apiClient.post('/admin/affectations/automatique/apercu', body)
      setApercu(res.data)
      return res.data
    } catch (err) {
      setErreur({ message: messageErreur(err, "Le calcul de l'aperçu a échoué."), code: err?.payload?.code ?? null })
      return null
    } finally {
      setChargement(false)
    }
  }, [])

  const executer = useCallback(async ({ criteres, nombreCorrespondants, nombreAffectables, evaluateurIds }) => {
    setExecution(true)
    setErreur(null)
    try {
      const body = { criteres, nombre_correspondants: nombreCorrespondants, nombre_affectables: nombreAffectables }
      if (evaluateurIds) body.evaluateur_ids = evaluateurIds
      const res = await apiClient.post('/admin/affectations/automatique', body)
      return res.data
    } catch (err) {
      setErreur({ message: messageErreur(err, "L'affectation automatique a échoué."), code: err?.payload?.code ?? null })
      throw err
    } finally {
      setExecution(false)
    }
  }, [])

  return { apercu, chargement, execution, erreur, calculerApercu, executer }
}
