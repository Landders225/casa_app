import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { Spinner } from '../../components/ui/Spinner.jsx'
import { apiClient } from '../../lib/apiClient.js'
import { formatDateFr } from '../../lib/formatDate.js'
import { evaluateurDossierPath } from '../../routing/routes.js'
import { AffectationAutomatiqueModal } from './AffectationAutomatiqueModal.jsx'
import { AssignModal } from './AssignModal.jsx'
import { MotifConfirmDialog } from './MotifConfirmDialog.jsx'
import { DECISION_FILTRE_OPTIONS, DECISION_LABELS, ELIGIBILITE_LABELS, STATUT_INTERNE_LABELS } from './optionLabels.js'
import { ReattributionModal } from './ReattributionModal.jsx'
import { useAffectation } from './useAffectation.js'
import { useCampagnes } from './useCampagnes.js'
import { criteresSupervision, useCandidaturesAdmin } from './useCandidaturesAdmin.js'
import { useElimination } from './useElimination.js'
import { useEvaluateurs } from './useEvaluateurs.js'
import { useReattribution } from './useReattribution.js'
import { calculerRetablissementDesactive, useRetablissementEligibilite } from './useRetablissementEligibilite.js'

const STATUTS = ['soumis', 'en_instruction', 'evalue', 'non_eligible']
const ELIGIBILITES = ['non_verifie', 'eligible', 'non_eligible']
const DELAI_DEBOUNCE_RECHERCHE_MS = 400

/**
 * Supervision des candidatures + affectation en masse (Lot 8d-1) + élimination
 * manuelle mono-cible (Lot 8d-3 — le backend n'a pas d'endpoint bulk, D-6b-4 :
 * contrairement au bouton « Marquer éliminé » EN MASSE de `candidatures.html`,
 * une seule candidature à la fois). `CandidatureAdminResource` (statut interne,
 * éligibilité, scores figés, décision) est LÉGITIME ici, jamais recalculée.
 *
 * Le lien « Voir » renvoie vers la fiche évaluateur EXISTANTE
 * (`/evaluateur/candidatures/:id`, recouvrement ADR-10 déjà acquis au 8c-1) —
 * pas de nouvelle fiche admin dédiée (Étape 1, Q5).
 *
 * Sélection « tous les N résultats » (ADR-45) : la case d'en-tête coche la
 * PAGE ; un bandeau propose ensuite d'étendre la sélection à tous les dossiers
 * correspondant aux critères. Ce mode ne transporte AUCUN identifiant : il
 * fige les critères et N au clic, et n'ouvre que la répartition automatique
 * (le mode page garde l'affectation manuelle à un évaluateur). Il est vidé,
 * comme la sélection, à tout changement de recherche/filtre/tri/page.
 */
export function CandidaturesSupervision() {
  const [rechercheSaisie, setRechercheSaisie] = useState('')
  const [recherche, setRecherche] = useState('')
  const [statutInterne, setStatutInterne] = useState('')
  const [filiere, setFiliere] = useState('')
  const [evaluateurFiltre, setEvaluateurFiltre] = useState('')
  const [campagneFiltre, setCampagneFiltre] = useState('')
  const [eligibiliteFiltre, setEligibiliteFiltre] = useState('')
  const [decisionFiltre, setDecisionFiltre] = useState('')
  const [triColonne, setTriColonne] = useState('')
  const [triDirection, setTriDirection] = useState('')
  const [page, setPage] = useState(1)
  const [filieres, setFilieres] = useState([])
  const [selected, setSelected] = useState(() => new Set())
  const [showAssign, setShowAssign] = useState(false)
  const [showReattribution, setShowReattribution] = useState(false)
  // Mode « tous les N résultats » : `{ criteres, nombre }` figés au clic, ou null.
  const [selectionTous, setSelectionTous] = useState(null)
  const [showAuto, setShowAuto] = useState(false)
  const [messageSucces, setMessageSucces] = useState(null)
  const [eliminationCible, setEliminationCible] = useState(null)
  // Lot 4 (ADR-49) : sélection SÉPARÉE de `selected` (Affecter/Réattribuer,
  // inchangés) — EXPLICITE par id, jamais vidée implicitement par un
  // changement de recherche/filtre/tri/page (correctif de revue : vider à
  // chaque recherche cassait le travail sur une liste). Ne se vide que sur
  // succès ou via « Vider la sélection » dans la modale, qui affiche toujours
  // la liste complète des numéros choisis — le risque d'« oubli » qui
  // justifiait l'ancien vidage automatique disparaît avec cette visibilité.
  // Map id -> { eligible, numeroDossier } : un instantané pris AU MOMENT de
  // la coche (pas une relecture d'`items`, qui ne contient que la page
  // courante) — nécessaire pour afficher le numéro et détecter un dossier
  // devenu entre-temps déjà éligible, même après avoir changé de page.
  const [selectionRetablissement, setSelectionRetablissement] = useState(() => new Map())
  const [showRetablissement, setShowRetablissement] = useState(false)
  const debounceRechercheRef = useRef(null)

  const { status, items, meta, reload } = useCandidaturesAdmin({
    recherche,
    statutInterne,
    filiere,
    evaluateur: evaluateurFiltre,
    campagne: campagneFiltre,
    eligibilite: eligibiliteFiltre,
    decision: decisionFiltre,
    triColonne,
    triDirection,
    page,
  })
  const { items: evaluateurs } = useEvaluateurs()
  // `tous: true` UNIQUEMENT pour le filtre ci-dessous : il doit aussi
  // proposer les évaluateurs désactivés (mention « désactivé »), jamais les
  // sélecteurs cibles (`evaluateurs` ci-dessus reste actifs seulement).
  const { items: evaluateursFiltre } = useEvaluateurs({ tous: true })
  const { items: campagnes } = useCampagnes()
  const { assign, assigning, error: assignError, resetError } = useAffectation()
  const { reattribuer, reattributing, error: reattributionError, resetError: resetReattributionError } = useReattribution()
  const { eliminer, eliminating, error: eliminationError, resetError: resetEliminationError } = useElimination()
  const { retablir, retablissant, error: retablissementError, resetError: resetRetablissementError } = useRetablissementEligibilite()

  useEffect(() => {
    apiClient.get('/filieres').then((res) => setFilieres(res.data ?? [])).catch(() => {})
  }, [])

  const resetPage = () => setPage(1)

  // Sélection vidée à chaque changement de recherche/filtre/tri/page : aucune
  // action groupée ne doit viser un dossier qui n'est plus affiché. Aucun
  // usage actuel ne repose sur une sélection conservée d'une page à l'autre
  // (« Affecter » lit `selected` immédiatement, dans la même page).
  useEffect(() => {
    // oxlint-disable-next-line react/set-state-in-effect
    setSelected(new Set())
    setSelectionTous(null)
  }, [recherche, statutInterne, filiere, evaluateurFiltre, campagneFiltre, eligibiliteFiltre, decisionFiltre, triColonne, triDirection, page])

  // Recherche appliquée ~400 ms après la dernière frappe (pas une requête par
  // lettre) — ou immédiatement sur Entrée (`onRechercheKeyDown`).
  useEffect(() => {
    debounceRechercheRef.current = setTimeout(() => {
      setRecherche(rechercheSaisie)
      resetPage()
    }, DELAI_DEBOUNCE_RECHERCHE_MS)
    return () => clearTimeout(debounceRechercheRef.current)
  }, [rechercheSaisie])

  const onRechercheKeyDown = (e) => {
    if (e.key !== 'Enter') return
    e.preventDefault()
    clearTimeout(debounceRechercheRef.current)
    setRecherche(rechercheSaisie)
    resetPage()
  }

  const trier = (colonne) => {
    if (triColonne === colonne) {
      setTriDirection((d) => (d === 'asc' ? 'desc' : 'asc'))
    } else {
      setTriColonne(colonne)
      setTriDirection('asc')
    }
    resetPage()
  }

  const reinitialiser = () => {
    setRechercheSaisie('')
    setRecherche('')
    setStatutInterne('')
    setFiliere('')
    setEvaluateurFiltre('')
    setCampagneFiltre('')
    setEligibiliteFiltre('')
    setDecisionFiltre('')
    setTriColonne('')
    setTriDirection('')
    setPage(1)
  }

  const toggle = (id) => setSelected((prev) => {
    const next = new Set(prev)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    return next
  })
  const toutDeselectionner = () => {
    setSelected(new Set())
    setSelectionTous(null)
  }
  const toggleAll = () => {
    if (selectionTous) {
      toutDeselectionner()
      return
    }
    setSelected((prev) => (
      prev.size === items.length ? new Set() : new Set(items.map((c) => c.id))
    ))
  }
  const pageEntiereCochee = items.length > 0 && selected.size === items.length
  // Affecter et Réattribuer ciblent des sélections mutuellement exclusives
  // (dossier libre vs dossier déjà affecté) — correctif de revue : avant,
  // les deux boutons restaient actifs quel que soit l'état des dossiers
  // cochés, et seule la réponse 422 du serveur révélait l'erreur.
  const selectionItems = items.filter((c) => selected.has(c.id))
  const selectionContientDejaAffecte = selectionItems.some((c) => c.evaluateur)
  const selectionContientNonAffecte = selectionItems.some((c) => !c.evaluateur)

  // Lot 4 (ADR-49) : sélection SÉPARÉE, voir le commentaire du state ci-dessus.
  const toggleRetablissement = (c) => setSelectionRetablissement((prev) => {
    const next = new Map(prev)
    if (next.has(c.id)) next.delete(c.id)
    else next.set(c.id, { eligible: c.statut_eligibilite_interne !== 'non_eligible', numeroDossier: c.numero_dossier })
    return next
  })
  const viderSelectionRetablissement = () => setSelectionRetablissement(new Map())
  const { desactive: retablissementDesactive, titre: titreRetablissementDesactive } = calculerRetablissementDesactive({
    selectionTousActif: Boolean(selectionTous),
    contientDejaEligible: [...selectionRetablissement.values()].some((snap) => snap.eligible),
    taille: selectionRetablissement.size,
  })
  const selectionnerTous = () => {
    setMessageSucces(null)
    setSelectionTous({
      criteres: criteresSupervision({
        recherche,
        statutInterne,
        filiere,
        evaluateur: evaluateurFiltre,
        campagne: campagneFiltre,
        eligibilite: eligibiliteFiltre,
        decision: decisionFiltre,
      }),
      nombre: meta?.total ?? items.length,
    })
  }

  const succesAuto = (res) => {
    const recoivent = res.evaluateurs.filter((e) => e.a_recevoir > 0).length
    setShowAuto(false)
    toutDeselectionner()
    setMessageSucces(`${res.affectees} dossier${res.affectees > 1 ? 's' : ''} réparti${res.affectees > 1 ? 's' : ''} entre ${recoivent} évaluateur${recoivent > 1 ? 's' : ''}.`)
    reload()
  }
  const annulerAuto = (code) => {
    setShowAuto(false)
    // La liste a changé côté serveur : on recharge et on fait reconfirmer.
    if (code === 'liste_modifiee') {
      toutDeselectionner()
      reload()
    }
  }

  const confirmAssign = async (evaluateurId) => {
    try {
      const res = await assign(evaluateurId, [...selected])
      setShowAssign(false)
      setSelected(new Set())
      reload()
      return res
    } catch {
      // Le message atomique (422, liste des refusées) reste affiché dans le
      // modal — la sélection n'est PAS vidée, l'utilisateur peut ajuster.
      return null
    }
  }

  const confirmReattribution = async (evaluateurId) => {
    try {
      const res = await reattribuer(evaluateurId, [...selected])
      setShowReattribution(false)
      setSelected(new Set())
      reload()
      return res
    } catch {
      // Même logique qu'`confirmAssign` : message atomique affiché tel
      // quel, sélection conservée pour ajuster.
      return null
    }
  }

  const confirmRetablissement = async (motif) => {
    try {
      await retablir([...selectionRetablissement.keys()], motif)
      setShowRetablissement(false)
      viderSelectionRetablissement()
      reload()
    } catch {
      // Même logique qu'`confirmAssign`/`confirmReattribution` : message
      // atomique affiché tel quel, sélection conservée pour ajuster.
    }
  }

  const confirmElimination = async (motif) => {
    try {
      await eliminer(eliminationCible.id, motif)
      setEliminationCible(null)
      reload()
    } catch {
      // `eliminationError` est déjà posé par le hook — le modal reste ouvert.
    }
  }

  const enTeteTriable = (colonne, libelle) => (
    <th>
      <button
        type="button"
        onClick={() => trier(colonne)}
        style={{
          background: 'none', border: 'none', cursor: 'pointer', display: 'flex',
          alignItems: 'center', gap: 4, font: 'inherit', color: 'inherit', padding: 0,
        }}
      >
        {libelle}
        <i
          className={`fa-solid ${triColonne === colonne ? (triDirection === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort'}`}
          aria-hidden="true"
        />
      </button>
    </th>
  )

  return (
    <AppShell title="Candidatures">
      <div className="page-head">
        <div>
          <h2>Candidatures</h2>
          <p className="text-muted">Supervision de l'ensemble des dossiers reçus, toutes filières confondues.</p>
        </div>
        <div className="flex gap-2">
          {selectionTous ? (
            <button type="button" className="btn btn-primary btn-sm" onClick={() => setShowAuto(true)}>
              <i className="fa-solid fa-shuffle" aria-hidden="true" /> Répartir automatiquement ({selectionTous.nombre})
            </button>
          ) : selected.size > 0 ? (
            <>
              <button
                type="button"
                className="btn btn-outline btn-sm"
                disabled={selectionContientDejaAffecte}
                title={selectionContientDejaAffecte ? 'Au moins un dossier sélectionné est déjà affecté — utilisez Réattribuer.' : undefined}
                onClick={() => { resetError(); setShowAssign(true) }}
              >
                <i className="fa-solid fa-user-plus" aria-hidden="true" /> Affecter ({selected.size})
              </button>
              <button
                type="button"
                className="btn btn-outline btn-sm"
                disabled={selectionContientNonAffecte}
                title={selectionContientNonAffecte ? "Au moins un dossier sélectionné n'est pas encore affecté — utilisez Affecter." : undefined}
                onClick={() => { resetReattributionError(); setShowReattribution(true) }}
              >
                <i className="fa-solid fa-right-left" aria-hidden="true" /> Réattribuer ({selected.size})
              </button>
            </>
          ) : null}
          {/* Lot 4 (ADR-49) : sélection SÉPARÉE de `selected` ci-dessus — ce
              bouton apparaît/disparaît indépendamment des deux autres. */}
          {selectionRetablissement.size > 0 ? (
            <button
              type="button"
              className="btn btn-outline btn-sm"
              disabled={retablissementDesactive}
              title={titreRetablissementDesactive}
              onClick={() => { resetRetablissementError(); setShowRetablissement(true) }}
            >
              <i className="fa-solid fa-rotate-left" aria-hidden="true" /> Rendre éligible ({selectionRetablissement.size})
            </button>
          ) : null}
        </div>
      </div>

      <div className="flex gap-3" style={{ marginBottom: 'var(--space-3)', flexWrap: 'wrap' }}>
        <input
          type="search"
          className="input"
          style={{ maxWidth: 260 }}
          placeholder="N° dossier, nom ou prénom…"
          aria-label="Rechercher un dossier"
          value={rechercheSaisie}
          onChange={(e) => setRechercheSaisie(e.target.value)}
          onKeyDown={onRechercheKeyDown}
        />
        <select className="select" style={{ maxWidth: 220 }} value={statutInterne} onChange={(e) => { setStatutInterne(e.target.value); resetPage() }}>
          <option value="">Tous les statuts</option>
          {STATUTS.map((s) => (
            <option key={s} value={s}>{STATUT_INTERNE_LABELS[s]?.label ?? s}</option>
          ))}
        </select>
        <select className="select" style={{ maxWidth: 220 }} value={eligibiliteFiltre} onChange={(e) => { setEligibiliteFiltre(e.target.value); resetPage() }}>
          <option value="">Toutes les éligibilités</option>
          {ELIGIBILITES.map((e) => (
            <option key={e} value={e}>{ELIGIBILITE_LABELS[e]?.label ?? e}</option>
          ))}
        </select>
        <select className="select" style={{ maxWidth: 220 }} value={decisionFiltre} onChange={(e) => { setDecisionFiltre(e.target.value); resetPage() }}>
          <option value="">Toutes les décisions</option>
          {DECISION_FILTRE_OPTIONS.map((d) => (
            <option key={d.value} value={d.value}>{d.label}</option>
          ))}
        </select>
        <select className="select" style={{ maxWidth: 220 }} value={filiere} onChange={(e) => { setFiliere(e.target.value); resetPage() }}>
          <option value="">Toutes les filières</option>
          {filieres.map((f) => (
            <option key={f.id} value={f.code}>{f.nom}</option>
          ))}
        </select>
        <select className="select" style={{ maxWidth: 220 }} value={evaluateurFiltre} onChange={(e) => { setEvaluateurFiltre(e.target.value); resetPage() }}>
          <option value="">Tous les évaluateurs</option>
          <option value="non_affecte">Non affecté</option>
          {evaluateursFiltre.map((e) => (
            <option key={e.id} value={e.id}>{e.prenom} {e.nom}{e.actif ? '' : ' (désactivé)'}</option>
          ))}
        </select>
        <select className="select" style={{ maxWidth: 220 }} value={campagneFiltre} onChange={(e) => { setCampagneFiltre(e.target.value); resetPage() }}>
          <option value="">Toutes les campagnes</option>
          {campagnes.map((c) => (
            <option key={c.id} value={c.id}>{c.nom}</option>
          ))}
        </select>
        <button type="button" className="btn btn-outline btn-sm" onClick={reinitialiser}>
          Réinitialiser
        </button>
      </div>

      {status === 'ready' ? (
        <p className="text-muted caption" style={{ marginBottom: 'var(--space-4)' }}>
          {meta?.total ?? items.length} dossier{(meta?.total ?? items.length) === 1 ? '' : 's'}
        </p>
      ) : null}

      {messageSucces ? (
        <div style={{ marginBottom: 'var(--space-3)' }}>
          <Alert variant="success">{messageSucces}</Alert>
        </div>
      ) : null}

      {status === 'ready' && selectionTous ? (
        <div className="alert alert-info" style={{ marginBottom: 'var(--space-3)' }}>
          <i className="fa-solid fa-circle-info" aria-hidden="true" />
          <div>
            <strong>
              Les {selectionTous.nombre} dossier{selectionTous.nombre > 1 ? 's' : ''} correspondant à votre recherche et vos filtres {selectionTous.nombre > 1 ? 'sont sélectionnés' : 'est sélectionné'}.
            </strong>{' '}
            <button type="button" className="btn btn-link btn-sm" onClick={toutDeselectionner}>
              Tout désélectionner
            </button>
          </div>
        </div>
      ) : status === 'ready' && pageEntiereCochee ? (
        <div className="alert alert-info" style={{ marginBottom: 'var(--space-3)' }}>
          <i className="fa-solid fa-circle-info" aria-hidden="true" />
          <div>
            {items.length < (meta?.total ?? items.length)
              ? `Les ${items.length} dossiers de cette page sont sélectionnés.`
              : `Les ${items.length} dossier${items.length > 1 ? 's' : ''} de la liste ${items.length > 1 ? 'sont sélectionnés' : 'est sélectionné'}.`}{' '}
            <button type="button" className="btn btn-link btn-sm" onClick={selectionnerTous}>
              {(meta?.total ?? items.length) > 1
                ? `Sélectionner les ${meta?.total ?? items.length} dossiers correspondant à votre recherche et vos filtres`
                : 'Sélectionner le dossier correspondant à votre recherche et vos filtres'}
            </button>
          </div>
        </div>
      ) : null}

      {status === 'loading' ? (
        <div className="text-center" style={{ padding: 'var(--space-16)' }}>
          <Spinner large />
        </div>
      ) : status === 'error' ? (
        <Alert variant="warning">La liste n'a pas pu être chargée. Réessayez plus tard.</Alert>
      ) : items.length === 0 ? (
        <div className="empty-state card">
          <div className="empty-icon">
            <i className="fa-solid fa-address-card" aria-hidden="true" />
          </div>
          <p>Aucun dossier pour ce filtre.</p>
        </div>
      ) : (
        <>
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th>
                    <input
                      type="checkbox"
                      aria-label="Tout sélectionner"
                      checked={Boolean(selectionTous) || pageEntiereCochee}
                      onChange={toggleAll}
                    />
                  </th>
                  {enTeteTriable('numero_dossier', 'N° dossier')}
                  {enTeteTriable('candidat', 'Candidat')}
                  <th>Filière</th>
                  <th>Statut</th>
                  <th>Éligibilité</th>
                  <th>Rendre éligible</th>
                  <th>Évaluateur</th>
                  {enTeteTriable('score_dossier', 'Score dossier')}
                  {enTeteTriable('score_entretien', 'Score entretien')}
                  <th>Décision</th>
                  {enTeteTriable('date_soumission', 'Date')}
                  <th aria-hidden="true" />
                </tr>
              </thead>
              <tbody>
                {items.map((c) => {
                  const statutInfo = STATUT_INTERNE_LABELS[c.statut_interne] ?? { label: c.statut_interne, badge: 'badge-neutral' }
                  const eligInfo = ELIGIBILITE_LABELS[c.statut_eligibilite_interne] ?? { label: c.statut_eligibilite_interne, badge: 'badge-neutral' }
                  const decisionInfo = c.decision ? (DECISION_LABELS[c.decision] ?? { label: c.decision, badge: 'badge-neutral' }) : null
                  return (
                    <tr key={c.id}>
                      <td>
                        <input
                          type="checkbox"
                          aria-label={`Sélectionner ${c.numero_dossier}`}
                          checked={Boolean(selectionTous) || selected.has(c.id)}
                          disabled={Boolean(selectionTous)}
                          onChange={() => toggle(c.id)}
                        />
                      </td>
                      <td><span className="caption fw-semibold">{c.numero_dossier}</span></td>
                      <td>
                        <div className="flex items-center gap-3">
                          <span className="avatar avatar-sm">{(c.candidat?.prenom?.[0] || '') + (c.candidat?.nom?.[0] || '')}</span>
                          <div>
                            <div className="fw-medium body-sm">{c.candidat?.prenom} {c.candidat?.nom}</div>
                            <div className="caption">{c.candidat?.ville_residence}</div>
                          </div>
                        </div>
                      </td>
                      <td>{c.filiere?.nom}</td>
                      <td><span className={`badge ${statutInfo.badge}`}>{statutInfo.label}</span></td>
                      <td>
                        <span className={`badge ${eligInfo.badge}`}>{eligInfo.label}</span>
                        {/* Lot 4 (ADR-49) : aide au choix (critères déclenchés) + badge
                            de rétablissement — admin only (CandidatureAdminResource seul
                            à les exposer, jamais côté évaluateur/candidat). */}
                        {c.statut_eligibilite_interne === 'non_eligible' && c.criteres_eliminatoires?.length > 0 ? (
                          <div className="caption text-muted" style={{ marginTop: 2 }}>
                            {c.criteres_eliminatoires.map((crit) => crit.code_critere).join(', ')}
                          </div>
                        ) : null}
                        {c.eligibilite_retablie ? (
                          <div style={{ marginTop: 2 }}>
                            <span className="badge badge-info">Éligibilité rétablie</span>
                          </div>
                        ) : null}
                      </td>
                      <td>
                        {/* Lot 4 (ADR-49, correctif de revue) : rien du tout sur
                            un dossier déjà éligible — pas même une case désactivée,
                            cette colonne n'a de sens que pour un dossier non éligible. */}
                        {c.statut_eligibilite_interne === 'non_eligible' ? (
                          <input
                            type="checkbox"
                            // Libellé délibérément distinct de « Sélectionner {n°} »
                            // (case Affecter/Réattribuer) : sélection SÉPARÉE (ADR-49),
                            // et une requête `/sélectionner {n°}/i` ne doit matcher
                            // qu'une seule case.
                            aria-label={`Rétablir l'éligibilité de ${c.numero_dossier}`}
                            checked={selectionRetablissement.has(c.id)}
                            onChange={() => toggleRetablissement(c)}
                          />
                        ) : null}
                      </td>
                      <td>{c.evaluateur ? `${c.evaluateur.prenom} ${c.evaluateur.nom}` : <span className="caption">Non affecté</span>}</td>
                      <td className="caption">{c.score_dossier ?? '—'}</td>
                      <td className="caption">{c.score_entretien ?? '—'}</td>
                      <td>{decisionInfo ? <span className={`badge ${decisionInfo.badge}`}>{decisionInfo.label}</span> : <span className="caption">—</span>}</td>
                      <td className="caption">{formatDateFr(c.date_soumission)}</td>
                      <td>
                        <div className="flex gap-2">
                          <Link to={evaluateurDossierPath(c.id)} className="btn btn-sm btn-outline">Voir</Link>
                          <button
                            type="button"
                            className="btn btn-sm btn-danger"
                            disabled={c.statut_eligibilite_interne === 'non_eligible'}
                            title={c.statut_eligibilite_interne === 'non_eligible' ? 'Déjà non éligible.' : undefined}
                            onClick={() => { resetEliminationError(); setEliminationCible(c) }}
                          >
                            Éliminer
                          </button>
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          {meta && meta.last_page > 1 ? (
            <div className="pagination" style={{ marginTop: 'var(--space-5)', justifyContent: 'center' }}>
              <button type="button" className="page-btn" aria-label="Page précédente" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                <i className="fa-solid fa-chevron-left" aria-hidden="true" />
              </button>
              <span className="caption">Page {meta.current_page} / {meta.last_page}</span>
              <button type="button" className="page-btn" aria-label="Page suivante" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>
                <i className="fa-solid fa-chevron-right" aria-hidden="true" />
              </button>
            </div>
          ) : null}
        </>
      )}

      {showAssign ? (
        <AssignModal
          count={selected.size}
          evaluateurs={evaluateurs}
          assigning={assigning}
          error={assignError}
          onCancel={() => setShowAssign(false)}
          onConfirm={confirmAssign}
        />
      ) : null}

      {showReattribution ? (
        <ReattributionModal
          count={selected.size}
          evaluateurs={evaluateurs}
          reattributing={reattributing}
          error={reattributionError}
          onCancel={() => setShowReattribution(false)}
          onConfirm={confirmReattribution}
        />
      ) : null}

      {showAuto && selectionTous ? (
        <AffectationAutomatiqueModal
          criteres={selectionTous.criteres}
          nombreCorrespondants={selectionTous.nombre}
          evaluateurs={evaluateurs}
          onCancel={annulerAuto}
          onSuccess={succesAuto}
        />
      ) : null}

      {eliminationCible ? (
        <MotifConfirmDialog
          title="Éliminer ce dossier ?"
          // Lot 4 (ADR-49) : reformulé — ce n'est plus vrai depuis le
          // rétablissement d'éligibilité (un acte distinct, également tracé,
          // pas un undo direct).
          message={`${eliminationCible.candidat?.prenom} ${eliminationCible.candidat?.nom} (${eliminationCible.numero_dossier}) sera marqué non éligible. Cette action peut être rapportée plus tard par un rétablissement d'éligibilité (un acte distinct, également tracé), jamais annulée directement.`}
          confirmLabel="Éliminer"
          loading={eliminating}
          error={eliminationError}
          onCancel={() => setEliminationCible(null)}
          onConfirm={confirmElimination}
        />
      ) : null}

      {showRetablissement ? (
        <MotifConfirmDialog
          title="Rendre éligible ?"
          message={`${selectionRetablissement.size} dossier${selectionRetablissement.size > 1 ? 's' : ''} sélectionné${selectionRetablissement.size > 1 ? 's' : ''} ${selectionRetablissement.size > 1 ? 'seront rendus' : 'sera rendu'} éligible${selectionRetablissement.size > 1 ? 's' : ''}. Cette action contourne les critères d'éligibilité automatiques (âge, diplôme, résidence…) et reste tracée (audit).`}
          // Lot 4 (ADR-49, correctif de revue) : la liste des numéros choisis
          // (jamais un nom) + « Vider la sélection » — la sélection n'étant
          // plus vidée implicitement par un changement de filtre/page, c'est
          // désormais le seul moyen (hors succès) de repartir de zéro.
          extra={
            <div style={{ marginTop: 'var(--space-3)' }}>
              <div className="flex items-center justify-between" style={{ marginBottom: 'var(--space-2)' }}>
                <span className="caption fw-semibold">Dossiers sélectionnés</span>
                <button
                  type="button"
                  className="btn btn-link btn-sm"
                  onClick={() => { viderSelectionRetablissement(); setShowRetablissement(false) }}
                >
                  Vider la sélection
                </button>
              </div>
              <ul
                aria-label="Numéros de dossier sélectionnés"
                className="caption"
                style={{ maxHeight: 160, overflowY: 'auto', listStyle: 'none', padding: 0, margin: 0 }}
              >
                {[...selectionRetablissement.values()].map((snap) => (
                  <li key={snap.numeroDossier}>{snap.numeroDossier}</li>
                ))}
              </ul>
            </div>
          }
          ackLabel="J'ai compris : ce rétablissement contourne les critères d'éligibilité automatiques et sera tracé."
          confirmLabel="Rendre éligible"
          loading={retablissant}
          error={retablissementError}
          onCancel={() => setShowRetablissement(false)}
          onConfirm={confirmRetablissement}
        />
      ) : null}
    </AppShell>
  )
}
