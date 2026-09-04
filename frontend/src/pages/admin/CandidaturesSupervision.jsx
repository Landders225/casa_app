import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { Spinner } from '../../components/ui/Spinner.jsx'
import { apiClient } from '../../lib/apiClient.js'
import { formatDateFr } from '../../lib/formatDate.js'
import { evaluateurDossierPath } from '../../routing/routes.js'
import { AssignModal } from './AssignModal.jsx'
import { DECISION_LABELS, ELIGIBILITE_LABELS, STATUT_INTERNE_LABELS } from './optionLabels.js'
import { useAffectation } from './useAffectation.js'
import { useCampagnes } from './useCampagnes.js'
import { useCandidaturesAdmin } from './useCandidaturesAdmin.js'
import { useEvaluateurs } from './useEvaluateurs.js'

const STATUTS = ['soumis', 'en_instruction', 'evalue', 'non_eligible']

/**
 * Supervision des candidatures + affectation en masse (Lot 8d-1). C'est la
 * vue reportée du 8c-1 (`candidatures.html`, hors « Marquer éliminé » — acte
 * exceptionnel, 8d-3). `CandidatureAdminResource` (statut interne,
 * éligibilité, scores figés, décision) est LÉGITIME ici, jamais recalculée.
 *
 * Le lien « Voir » renvoie vers la fiche évaluateur EXISTANTE
 * (`/evaluateur/candidatures/:id`, recouvrement ADR-10 déjà acquis au 8c-1) —
 * pas de nouvelle fiche admin dédiée (Étape 1, Q5).
 */
export function CandidaturesSupervision() {
  const [statutInterne, setStatutInterne] = useState('')
  const [filiere, setFiliere] = useState('')
  const [evaluateurFiltre, setEvaluateurFiltre] = useState('')
  const [campagneFiltre, setCampagneFiltre] = useState('')
  const [page, setPage] = useState(1)
  const [filieres, setFilieres] = useState([])
  const [selected, setSelected] = useState(() => new Set())
  const [showAssign, setShowAssign] = useState(false)

  const { status, items, meta, reload } = useCandidaturesAdmin({
    statutInterne, filiere, evaluateur: evaluateurFiltre, campagne: campagneFiltre, page,
  })
  const { items: evaluateurs } = useEvaluateurs()
  const { items: campagnes } = useCampagnes()
  const { assign, assigning, error: assignError, resetError } = useAffectation()

  useEffect(() => {
    apiClient.get('/filieres').then((res) => setFilieres(res.data ?? [])).catch(() => {})
  }, [])

  const resetPage = () => setPage(1)
  const toggle = (id) => setSelected((prev) => {
    const next = new Set(prev)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    return next
  })
  const toggleAll = () => setSelected((prev) => (
    prev.size === items.length ? new Set() : new Set(items.map((c) => c.id))
  ))

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

  return (
    <AppShell title="Candidatures">
      <div className="page-head">
        <div>
          <h2>Candidatures</h2>
          <p className="text-muted">Supervision de l'ensemble des dossiers reçus, toutes filières confondues.</p>
        </div>
        {selected.size > 0 ? (
          <div className="flex gap-2">
            <button type="button" className="btn btn-outline btn-sm" onClick={() => { resetError(); setShowAssign(true) }}>
              <i className="fa-solid fa-user-plus" aria-hidden="true" /> Affecter ({selected.size})
            </button>
          </div>
        ) : null}
      </div>

      <div className="flex gap-3" style={{ marginBottom: 'var(--space-5)', flexWrap: 'wrap' }}>
        <select className="select" style={{ maxWidth: 220 }} value={statutInterne} onChange={(e) => { setStatutInterne(e.target.value); resetPage() }}>
          <option value="">Tous les statuts</option>
          {STATUTS.map((s) => (
            <option key={s} value={s}>{STATUT_INTERNE_LABELS[s]?.label ?? s}</option>
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
          {evaluateurs.map((e) => (
            <option key={e.id} value={e.id}>{e.prenom} {e.nom}</option>
          ))}
        </select>
        <select className="select" style={{ maxWidth: 220 }} value={campagneFiltre} onChange={(e) => { setCampagneFiltre(e.target.value); resetPage() }}>
          <option value="">Toutes les campagnes</option>
          {campagnes.map((c) => (
            <option key={c.id} value={c.id}>{c.nom}</option>
          ))}
        </select>
      </div>

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
                  <th aria-hidden="true">
                    <input
                      type="checkbox"
                      aria-label="Tout sélectionner"
                      checked={selected.size === items.length && items.length > 0}
                      onChange={toggleAll}
                    />
                  </th>
                  <th>N° dossier</th>
                  <th>Candidat</th>
                  <th>Filière</th>
                  <th>Statut</th>
                  <th>Éligibilité</th>
                  <th>Évaluateur</th>
                  <th>Score dossier</th>
                  <th>Score entretien</th>
                  <th>Décision</th>
                  <th>Date</th>
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
                          checked={selected.has(c.id)}
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
                      <td><span className={`badge ${eligInfo.badge}`}>{eligInfo.label}</span></td>
                      <td>{c.evaluateur ? `${c.evaluateur.prenom} ${c.evaluateur.nom}` : <span className="caption">Non affecté</span>}</td>
                      <td className="caption">{c.score_dossier ?? '—'}</td>
                      <td className="caption">{c.score_entretien ?? '—'}</td>
                      <td>{decisionInfo ? <span className={`badge ${decisionInfo.badge}`}>{decisionInfo.label}</span> : <span className="caption">—</span>}</td>
                      <td className="caption">{formatDateFr(c.date_soumission)}</td>
                      <td>
                        <Link to={evaluateurDossierPath(c.id)} className="btn btn-sm btn-outline">Voir</Link>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          {meta && meta.last_page > 1 ? (
            <div className="pagination" style={{ marginTop: 'var(--space-5)', justifyContent: 'center' }}>
              <button type="button" className="page-btn" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                <i className="fa-solid fa-chevron-left" aria-hidden="true" />
              </button>
              <span className="caption">Page {meta.current_page} / {meta.last_page}</span>
              <button type="button" className="page-btn" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>
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
    </AppShell>
  )
}
