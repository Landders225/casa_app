import { useCallback, useRef, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

function messageErreur(err, defaut) {
  if (!(err instanceof ApiError)) return defaut
  return err.validationMessages[0] || err.message || defaut
}

/**
 * Affectation automatique par critères (ADR-45, ADR-48) — aperçu puis exécution.
 *
 *   POST /admin/affectations/automatique/apercu  { criteres, evaluateur_ids?, nombre_correspondants, cibles? }
 *   POST /admin/affectations/automatique         { …, nombre_affectables, repartition_attendue?, non_affectes_attendu? }
 *
 * Aucune liste d'identifiants : le serveur recalcule l'ensemble à partir des
 * critères et refuse (409 `liste_modifiee`) si ses nombres diffèrent de ceux
 * que l'administrateur a vus. Les messages du serveur sont affichés TELS
 * QUELS ; `code` (`err.payload.code`) est exposé pour adapter l'action proposée.
 *
 * `cibles` (Lot 3, ADR-48) : facultatif, `[{evaluateur_id, nombre}…]`. Si
 * fourni à l'exécution, `repartition_attendue`/`non_affectes_attendu` (ce que
 * l'aperçu a montré) sont renvoyés aussi : la charge des évaluateurs ayant pu
 * changer entre-temps, le serveur refuse (409 `liste_modifiee`) si le reliquat
 * ou les nombres par évaluateur ne retombent plus pareil.
 *
 * `calculerApercu` est relancé à chaque changement de sélection/cible
 * (ADR-45/48) : un aperçu plus ANCIEN peut répondre APRÈS un plus récent
 * (réseau). Un compteur de requête (correctif de revue) ignore toute réponse
 * qui n'est plus la dernière lancée — jamais d'aperçu périmé affiché par-
 * dessus le bon.
 */
export function useAffectationAutomatique() {
  const [apercu, setApercu] = useState(null)
  const [chargement, setChargement] = useState(false)
  const [execution, setExecution] = useState(false)
  const [erreur, setErreur] = useState(null)
  const derniereRequete = useRef(0)

  const calculerApercu = useCallback(async ({ criteres, nombreCorrespondants, evaluateurIds, cibles }) => {
    const requete = ++derniereRequete.current
    setChargement(true)
    setErreur(null)
    setApercu(null)
    try {
      const body = { criteres, nombre_correspondants: nombreCorrespondants }
      if (evaluateurIds) body.evaluateur_ids = evaluateurIds
      if (cibles) body.cibles = cibles
      const res = await apiClient.post('/admin/affectations/automatique/apercu', body)
      if (requete !== derniereRequete.current) return null
      setApercu(res.data)
      return res.data
    } catch (err) {
      if (requete !== derniereRequete.current) return null
      setErreur({ message: messageErreur(err, "Le calcul de l'aperçu a échoué."), code: err?.payload?.code ?? null })
      return null
    } finally {
      if (requete === derniereRequete.current) setChargement(false)
    }
  }, [])

  const executer = useCallback(async ({
    criteres, nombreCorrespondants, nombreAffectables, evaluateurIds, cibles, repartitionAttendue, nonAffectesAttendu,
  }) => {
    setExecution(true)
    setErreur(null)
    try {
      const body = { criteres, nombre_correspondants: nombreCorrespondants, nombre_affectables: nombreAffectables }
      if (evaluateurIds) body.evaluateur_ids = evaluateurIds
      if (cibles) {
        body.cibles = cibles
        body.repartition_attendue = repartitionAttendue
        body.non_affectes_attendu = nonAffectesAttendu
      }
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
