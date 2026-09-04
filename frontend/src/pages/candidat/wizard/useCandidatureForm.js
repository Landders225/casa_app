import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'

/** Colonnes `reponse_formulaire` gérées par le wizard (contrat 3a/3c). */
const REPONSE_FIELDS = [
  'sc01_scolarise_actuellement', 'sc02_derniere_classe', 'sc03_document_justifiant_niveau',
  'sc05_beneficiaire_formation_actuelle', 'sc06_deja_beneficie_formation', 'sc07_filiere_suivie',
  'sc08_mene_a_terme', 'sc09_motif_non_achevement',
  'se01_vit_avec', 'se02_orphelin', 'se03_situation_emploi', 'se04_source_revenu',
  'se05_personnes_a_charge', 'se06_soutien_menage',
  'langue_ecrit', 'langue_parle', 'langue_comprehension', 'info_word', 'info_excel', 'info_internet',
  'mo04_lettre_motivation',
  'di01_disponible_lun_ven', 'di02_contraintes', 'di03_engagement_complet',
  'acces_plateau', 'acces_deux_plateaux_vallons',
]

function emptyReponses() {
  return Object.fromEntries(REPONSE_FIELDS.map((f) => [f, null]))
}

function reponsesFromResource(res) {
  const out = emptyReponses()
  const src = res?.reponses || {}
  for (const f of REPONSE_FIELDS) {
    if (src[f] !== undefined) out[f] = src[f]
  }
  return out
}

function experiencesFromResource(res) {
  return (res?.experiences || []).map((e) => ({
    id: e.id,
    domaine: e.domaine ?? '',
    duree_categorie: e.duree_categorie ?? '',
    justificatif: e.justificatif ?? null,
  }))
}

function classementFromResource(res, filieres) {
  const rows = [...(res?.classement || [])].sort((a, b) => a.rang - b.rang)
  if (rows.length === 5) return rows.map((r) => ({ id: r.filiere.id, nom: r.filiere.nom }))
  // Repli : ordre alphabétique des filières connues.
  return [...filieres].sort((a, b) => a.nom.localeCompare(b.nom)).map((f) => ({ id: f.id, nom: f.nom }))
}

function piecesFromResource(res) {
  const out = {}
  for (const p of res?.pieces_dossier || []) out[p.type_document_code] = p
  return out
}

let tempSeq = 0

/**
 * État complet du formulaire de candidature (wizard 10 étapes).
 *
 * Persistance HYBRIDE :
 *  - réponses -> `PATCH .../reponses` en lot (champs modifiés) sur « Suivant » /
 *    « Brouillon ». « Suivant » ATTEND la confirmation avant d'avancer.
 *  - expériences / pièces / classement -> immédiats (IDs serveur, stockage).
 *  - la candidature est créée à l'étape « filière ».
 */
export function useCandidatureForm() {
  const [status, setStatus] = useState('loading')
  const [candidature, setCandidature] = useState(null)
  const [filieres, setFilieres] = useState([])
  const [reponses, setReponses] = useState(emptyReponses)
  const [experiences, setExperiences] = useState([])
  const [classement, setClassement] = useState([])
  const [pieces, setPieces] = useState({})
  const [saving, setSaving] = useState(false)
  const [serverErrors, setServerErrors] = useState({})

  const dirtyRef = useRef(new Set())
  const classementDirtyRef = useRef(false)

  const hydrate = useCallback((res, fils) => {
    setCandidature(res)
    setReponses(reponsesFromResource(res))
    setExperiences(experiencesFromResource(res))
    setClassement(classementFromResource(res, fils))
    setPieces(piecesFromResource(res))
    dirtyRef.current = new Set()
    classementDirtyRef.current = false
  }, [])

  useEffect(() => {
    let alive = true
    ;(async () => {
      try {
        const filieresRes = await apiClient.get('/filieres')
        const fils = filieresRes.data ?? []
        if (!alive) return
        setFilieres(fils)
        try {
          const c = await apiClient.get('/candidature')
          if (!alive) return
          if (c.data.statut_public !== 'brouillon') {
            setCandidature(c.data)
            setStatus('submitted')
          } else {
            hydrate(c.data, fils)
            setStatus('ready')
          }
        } catch (err) {
          if (err instanceof ApiError && err.status === 404) {
            setClassement(classementFromResource(null, fils))
            setStatus('ready')
          } else {
            throw err
          }
        }
      } catch {
        if (alive) setStatus('error')
      }
    })()
    return () => {
      alive = false
    }
  }, [hydrate])

  // Miroirs toujours à jour (évitent les closures périmées ET le piège de
  // l'updater `setState(fn)` qui n'est PAS exécuté au moment de l'appel).
  const reponsesRef = useRef(reponses)
  useEffect(() => {
    reponsesRef.current = reponses
  }, [reponses])

  const experiencesRef = useRef(experiences)
  useEffect(() => {
    experiencesRef.current = experiences
  }, [experiences])

  // --- Réponses ---
  const setReponse = useCallback((field, value) => {
    setReponses((r) => ({ ...r, [field]: value }))
    dirtyRef.current.add(field)
    setServerErrors((e) => (e[field] ? { ...e, [field]: undefined } : e))
  }, [])

  /** Envoie les champs modifiés. Renvoie true si OK (ou rien à envoyer). */
  const saveReponses = useCallback(async () => {
    const fields = [...dirtyRef.current]
    if (fields.length === 0 || !candidature) return true
    setSaving(true)
    try {
      const body = Object.fromEntries(fields.map((f) => [f, reponsesRef.current[f]]))
      const res = await apiClient.patch(`/candidatures/${candidature.id}/reponses`, body)
      setCandidature(res.data)
      dirtyRef.current = new Set()
      setServerErrors({})
      return true
    } catch (err) {
      if (err instanceof ApiError && err.kind === 'validation') {
        setServerErrors(Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])))
      }
      throw err
    } finally {
      setSaving(false)
    }
  }, [candidature])

  // --- Candidature / filière ---
  const createCandidature = useCallback(async (filiereId) => {
    if (candidature) return candidature
    const res = await apiClient.post('/candidatures', { filiere_id: filiereId })
    setCandidature(res.data)
    setClassement(classementFromResource(res.data, filieres))
    setReponses(reponsesFromResource(res.data))
    return res.data
  }, [candidature, filieres])

  const confirmFiliere = useCallback(async (cand) => {
    const target = cand ?? candidature
    if (!target || target.cqp_confirme) return
    const res = await apiClient.post(`/candidatures/${target.id}/confirmer-filiere`)
    setCandidature(res.data)
  }, [candidature])

  // --- Expériences ---
  const addExperience = useCallback(() => {
    setExperiences((xs) => [...xs, { id: `tmp-${++tempSeq}`, domaine: '', duree_categorie: '', justificatif: null }])
  }, [])

  const setExperienceField = useCallback(async (rowId, field, value) => {
    const current = experiencesRef.current.find((x) => x.id === rowId)
    if (!current) return
    const target = { ...current, [field]: value }
    setExperiences((xs) => xs.map((x) => (x.id === rowId ? target : x)))
    if (!candidature) return
    const isTemp = String(rowId).startsWith('tmp-')
    if (isTemp) {
      // Les deux champs saisis -> on matérialise la ligne côté serveur (elle
      // devient éditable + prête à recevoir le justificatif).
      if (target.domaine && target.duree_categorie) {
        const res = await apiClient.post(`/candidatures/${candidature.id}/experiences`, {
          domaine: target.domaine,
          duree_categorie: target.duree_categorie,
        })
        setExperiences((xs) => xs.map((x) => (x.id === rowId ? { ...x, id: res.data.id } : x)))
      }
    } else {
      await apiClient.patch(`/candidatures/${candidature.id}/experiences/${rowId}`, { [field]: value })
    }
  }, [candidature])

  const removeExperience = useCallback(async (rowId) => {
    if (candidature && !String(rowId).startsWith('tmp-')) {
      await apiClient.del(`/candidatures/${candidature.id}/experiences/${rowId}`)
    }
    setExperiences((xs) => xs.filter((x) => x.id !== rowId))
  }, [candidature])

  const uploadExperienceJustif = useCallback(async (rowId, file) => {
    if (!candidature || String(rowId).startsWith('tmp-')) return
    const form = new FormData()
    form.append('fichier', file)
    const res = await apiClient.postForm(`/candidatures/${candidature.id}/experiences/${rowId}/justificatif`, form)
    setExperiences((xs) => xs.map((x) => (x.id === rowId ? { ...x, justificatif: res.data } : x)))
  }, [candidature])

  const removeExperienceJustif = useCallback(async (rowId) => {
    if (!candidature) return
    await apiClient.del(`/candidatures/${candidature.id}/experiences/${rowId}/justificatif`)
    setExperiences((xs) => xs.map((x) => (x.id === rowId ? { ...x, justificatif: null } : x)))
  }, [candidature])

  // --- Pièces du dossier ---
  const uploadPiece = useCallback(async (type, file) => {
    if (!candidature) return
    const form = new FormData()
    form.append('fichier', file)
    const res = await apiClient.postForm(`/candidatures/${candidature.id}/pieces/${type}`, form)
    setPieces((p) => ({ ...p, [type]: res.data }))
  }, [candidature])

  const removePiece = useCallback(async (type) => {
    if (!candidature) return
    await apiClient.del(`/candidatures/${candidature.id}/pieces/${type}`)
    setPieces((p) => {
      const next = { ...p }
      delete next[type]
      return next
    })
  }, [candidature])

  // --- Classement ---
  const classementRef = useRef(classement)
  useEffect(() => {
    classementRef.current = classement
  }, [classement])

  const reorderClassement = useCallback((orderedIds) => {
    setClassement((cur) => orderedIds.map((id) => cur.find((c) => c.id === id)))
    classementDirtyRef.current = true
  }, [])

  const saveClassement = useCallback(async () => {
    if (!candidature || !classementDirtyRef.current) return true
    const ordre = classementRef.current.map((c) => c.id)
    await apiClient.put(`/candidatures/${candidature.id}/classement`, { ordre })
    classementDirtyRef.current = false
    return true
  }, [candidature])

  // --- Profil (étape identité) ---
  const updateProfil = useCallback(async (patch) => {
    const res = await apiClient.patch('/candidat/profil', patch)
    return res.data
  }, [])

  // --- Soumission ---
  const submit = useCallback(async () => {
    if (!candidature) return { errors: { cqp_confirme: ['Créez d’abord votre candidature.'] } }
    try {
      const res = await apiClient.post(`/candidatures/${candidature.id}/soumettre`)
      return { data: res.data }
    } catch (err) {
      if (err instanceof ApiError && err.kind === 'validation') {
        return { errors: err.errors }
      }
      if (err instanceof ApiError && err.status === 409) {
        setStatus('submitted')
        return { conflict: true }
      }
      throw err
    }
  }, [candidature])

  const filiereById = useMemo(() => Object.fromEntries(filieres.map((f) => [f.id, f])), [filieres])

  return {
    status,
    candidature,
    filieres,
    filiereById,
    reponses,
    experiences,
    classement,
    pieces,
    saving,
    serverErrors,
    setServerErrors,
    setReponse,
    saveReponses,
    createCandidature,
    confirmFiliere,
    updateProfil,
    addExperience,
    setExperienceField,
    removeExperience,
    uploadExperienceJustif,
    removeExperienceJustif,
    uploadPiece,
    removePiece,
    reorderClassement,
    saveClassement,
    submit,
  }
}
