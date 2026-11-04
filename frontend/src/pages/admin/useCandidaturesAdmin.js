import { useCallback, useEffect, useRef, useState } from 'react'
import { apiClient } from '../../lib/apiClient.js'

/**
 * Vue de supervision — `POST /api/admin/candidatures` (Lot 6a/8d-1, recherche
 * Lot recherche-admin). POST (pas GET, ADR-39) : `recherche` peut contenir un
 * nom de candidat, qui ne doit jamais transiter par une query string archivée
 * en clair par les access log nginx/Apache. Même patron que `useDossiers.js`
 * (évaluateur, 8c-1) : ce hook ne fait que passer les filtres et exposer la
 * réponse telle quelle (pagination Laravel `data` + `meta`) —
 * `CandidatureAdminResource` a déjà tout calculé côté serveur.
 *
 * Compteur de requête (pas d'`AbortController` dans `apiClient`, qui n'expose
 * pas la requête `fetch` sous-jacente) : une réponse qui arrive alors qu'une
 * recherche plus récente est partie est silencieusement ignorée.
 */
/**
 * Critères de SÉLECTION (filtres + recherche, sans tri ni page) au format du
 * corps de `POST /admin/candidatures` — SOURCE UNIQUE côté client, aussi
 * envoyée telle quelle à l'affectation automatique (ADR-45) : « les N
 * dossiers correspondant à vos critères » sont exactement ceux de la liste.
 * Seules les valeurs renseignées sont transmises.
 */
export function criteresSupervision({
  recherche = '',
  statutInterne = '',
  filiere = '',
  evaluateur = '',
  campagne = '',
  eligibilite = '',
  decision = '',
} = {}) {
  const criteres = {}
  if (recherche) criteres.recherche = recherche
  if (statutInterne) criteres.statut_interne = statutInterne
  if (filiere) criteres.filiere = filiere
  if (evaluateur) criteres.evaluateur = evaluateur
  if (campagne) criteres.campagne = campagne
  if (eligibilite) criteres.eligibilite = eligibilite
  if (decision) criteres.decision = decision
  return criteres
}

export function useCandidaturesAdmin({
  recherche = '',
  statutInterne = '',
  filiere = '',
  evaluateur = '',
  campagne = '',
  eligibilite = '',
  decision = '',
  triColonne = '',
  triDirection = '',
  page = 1,
} = {}) {
  const [state, setState] = useState({ status: 'loading', items: [], meta: null })
  const requeteEnCours = useRef(0)

  const load = useCallback(() => {
    const id = ++requeteEnCours.current
    setState((s) => ({ ...s, status: 'loading' }))

    const body = criteresSupervision({ recherche, statutInterne, filiere, evaluateur, campagne, eligibilite, decision })
    if (triColonne) body.tri_colonne = triColonne
    if (triDirection) body.tri_direction = triDirection
    if (page > 1) body.page = page

    apiClient
      .post('/admin/candidatures', body)
      .then((res) => {
        if (requeteEnCours.current !== id) return
        setState({ status: 'ready', items: res.data ?? [], meta: res.meta ?? null })
      })
      .catch(() => {
        if (requeteEnCours.current === id) setState({ status: 'error', items: [], meta: null })
      })
  }, [recherche, statutInterne, filiere, evaluateur, campagne, eligibilite, decision, triColonne, triDirection, page])

  // oxlint-disable-next-line react/set-state-in-effect
  useEffect(() => load(), [load])

  return { ...state, reload: load }
}
