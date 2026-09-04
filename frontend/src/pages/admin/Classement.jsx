import { useState } from 'react'
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { FullPageSpinner, Spinner } from '../../components/ui/Spinner.jsx'
import { formatDateFr } from '../../lib/formatDate.js'
import { adminClassementPath, evaluateurDossierPath } from '../../routing/routes.js'
import './Classement.css'
import { DepartageBadges } from './DepartageBadges.jsx'
import { MotifModal } from './MotifModal.jsx'
import { PublishConfirmDialog } from './PublishConfirmDialog.jsx'
import { useCampagnes } from './useCampagnes.js'
import { useClassement } from './useClassement.js'

const DECISION_BADGE = { retenu: 'badge-success', liste_attente: 'badge-warning', non_retenu: 'badge-danger' }
const DECISION_LABEL = { retenu: 'Retenu', liste_attente: 'Liste d’attente', non_retenu: 'Non retenu' }
const MEDAL_CLASS = { 1: 'gold', 2: 'silver', 3: 'bronze' }

/**
 * Classement & publication (Lot 8d-2) — `/admin/classement/:campagneId`.
 *
 * LE SCORE FINAL, LE RANG ET LE DÉPARTAGE VIENNENT TOUJOURS DE L'API
 * (ADR-02/04) : `useClassement` remplace intégralement son état à chaque
 * réponse serveur. Les `lignes[]` arrivent DÉJÀ TRIÉES par `rang` — cet écran
 * ne fait AUCUN `.sort()` sur les données de classement.
 *
 * Zone la PLUS confidentielle du système (rang, score final, motif interne) —
 * administrateur strict absolu, jamais de pont vers un autre rôle (garde
 * noBridge déjà symétrique depuis le 8d-1).
 */
export function Classement() {
  const { campagneId } = useParams()
  const navigate = useNavigate()
  const { status: campagnesStatus, items: campagnes } = useCampagnes()
  const { status, data, calculating, publishing, savingMotif, error, calculer, publier, enregistrerMotifs } = useClassement(campagneId)
  const [activeCode, setActiveCode] = useState(null)
  const [motifLigne, setMotifLigne] = useState(null)
  const [confirmingPublish, setConfirmingPublish] = useState(false)

  // Pas de campagne dans l'URL : redirige vers la plus récente (déjà triée
  // serveur par `useCampagnes`, `orderByDesc(date_ouverture)` — aucun tri client).
  // `<Navigate>` déclaratif (pas `navigate()` impératif pendant le rendu, cf.
  // ProtectedRoute.jsx) — le composant se démonte proprement, pas de warning React.
  if (!campagneId) {
    if (campagnesStatus === 'ready' && campagnes.length > 0) {
      return <Navigate to={adminClassementPath(campagnes[0].id)} replace />
    }
    if (campagnesStatus === 'ready') {
      return (
        <AppShell title="Classement">
          <div className="empty-state card">
            <p>Aucune campagne pour le moment.</p>
          </div>
        </AppShell>
      )
    }
    return <FullPageSpinner />
  }

  if (status === 'loading' || status === 'idle') return <FullPageSpinner />

  if (status === 'error' || !data) {
    return (
      <AppShell title="Classement">
        <Alert variant="warning">Le classement n'a pas pu être chargé. Réessayez plus tard.</Alert>
      </AppShell>
    )
  }

  const filiereActive = data.filieres.find((f) => f.filiere.code === activeCode) ?? data.filieres[0]

  const lancerCalcul = async () => {
    try {
      await calculer()
    } catch {
      // `error` déjà posé par le hook.
    }
  }

  const confirmerPublication = async () => {
    try {
      await publier()
      setConfirmingPublish(false)
    } catch {
      // `error` déjà posé par le hook ; le dialogue reste ouvert pour relire le message.
    }
  }

  const enregistrerMotif = async (motifs) => {
    await enregistrerMotifs(motifLigne.candidature_id, motifs)
    setMotifLigne(null)
  }

  return (
    <AppShell title="Classement">
      <div className="page-head">
        <div>
          <h2>Classement des candidats</h2>
          <p className="text-muted">Score final (dossier + entretien) — n'inclut que les candidats dont l'entretien est validé.</p>
        </div>
        <div className="flex gap-2 items-center" style={{ flexWrap: 'wrap' }}>
          <select
            className="select"
            aria-label="Campagne"
            value={campagneId}
            onChange={(e) => navigate(adminClassementPath(e.target.value))}
          >
            {campagnes.map((c) => (
              <option key={c.id} value={c.id}>{c.nom}</option>
            ))}
          </select>
          {data.publie ? (
            <span className="badge badge-success badge-lg">
              <i className="fa-solid fa-circle-check" aria-hidden="true" /> Résultats publiés
            </span>
          ) : (
            <>
              <button type="button" className="btn btn-outline btn-sm" disabled={calculating} onClick={lancerCalcul}>
                {calculating ? 'Calcul…' : data.calcule ? 'Recalculer le classement' : 'Calculer le classement'}
              </button>
              <button
                type="button"
                className="btn btn-primary btn-sm"
                disabled={!data.calcule}
                title={data.calcule ? undefined : 'Calculez le classement avant de pouvoir publier.'}
                onClick={() => setConfirmingPublish(true)}
              >
                <i className="fa-solid fa-bullhorn" aria-hidden="true" /> Publier les résultats
              </button>
            </>
          )}
        </div>
      </div>

      {data.publie ? (
        <div className="lock-banner" style={{ marginBottom: 'var(--space-5)' }}>
          <i className="fa-solid fa-lock" style={{ fontSize: '1.2rem' }} aria-hidden="true" />
          <div>
            🔒 RÉSULTATS PUBLIÉS
            <div className="caption fw-normal" style={{ color: 'inherit', opacity: 0.85 }}>
              Publiés le {formatDateFr(data.publiee_le)}
              {data.publiee_par ? ` par ${data.publiee_par.prenom} ${data.publiee_par.nom}` : ''}. Irréversible — le
              classement reste visible en consultation, mais ne peut plus être recalculé ni modifié.
            </div>
          </div>
        </div>
      ) : null}

      {error ? (
        <div style={{ marginBottom: 'var(--space-5)' }}>
          <Alert variant="danger">{error}</Alert>
        </div>
      ) : null}

      {!data.calcule ? (
        <div className="empty-state card">
          <div className="empty-icon">
            <i className="fa-solid fa-ranking-star" aria-hidden="true" />
          </div>
          <h3>Aucun classement calculé</h3>
          <p>Cliquez sur « Calculer le classement » pour classer les candidats dont l'entretien est validé.</p>
        </div>
      ) : (
        <>
          <div className="tabs" style={{ marginBottom: 'var(--space-6)' }}>
            {data.filieres.map((f) => (
              <button
                key={f.filiere.code}
                type="button"
                className={`tab${f.filiere.code === filiereActive.filiere.code ? ' is-active' : ''}`}
                onClick={() => setActiveCode(f.filiere.code)}
              >
                {f.filiere.nom}
              </button>
            ))}
          </div>

          <div className="grid grid-3" style={{ marginBottom: 'var(--space-6)' }}>
            <div className="card kpi-card">
              <span className="caption">Places disponibles</span>
              <div className="kpi-value">{filiereActive.quota}</div>
            </div>
            <div className="card kpi-card">
              <span className="caption">Retenus</span>
              <div className="kpi-value" style={{ color: 'var(--casa-success-700)' }}>{filiereActive.retenus}</div>
            </div>
            <div className="card kpi-card">
              <span className="caption">Liste d'attente</span>
              <div className="kpi-value" style={{ color: 'var(--casa-warning-700)' }}>
                {filiereActive.liste_attente} / {data.liste_attente_taille}
              </div>
            </div>
          </div>

          {filiereActive.lignes.length === 0 ? (
            <div className="empty-state card">
              <div className="empty-icon">
                <i className="fa-solid fa-ranking-star" aria-hidden="true" />
              </div>
              <h3>Aucun candidat classé pour cette filière</h3>
            </div>
          ) : (
            <div className="table-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th>Rang</th>
                    <th>Candidat</th>
                    <th>Ville</th>
                    <th>Score final</th>
                    <th>Décision</th>
                    <th aria-hidden="true" />
                  </tr>
                </thead>
                <tbody>
                  {filiereActive.lignes.map((l) => (
                    <tr key={l.candidature_id} className={`decision-row ${l.decision}`}>
                      <td>
                        {l.rang == null ? (
                          <span className="caption">—</span>
                        ) : MEDAL_CLASS[l.rang] ? (
                          <span className={`medal ${MEDAL_CLASS[l.rang]}`}>{l.rang}</span>
                        ) : (
                          <span className="fw-semibold">{l.rang}</span>
                        )}
                      </td>
                      <td>
                        <div className="flex items-center gap-3">
                          <span className="avatar avatar-sm">{(l.candidat.prenom?.[0] || '') + (l.candidat.nom?.[0] || '')}</span>
                          <div>
                            <div className="fw-medium body-sm">{l.candidat.prenom} {l.candidat.nom}</div>
                            <div className="caption">{l.numero_dossier}</div>
                          </div>
                        </div>
                      </td>
                      <td>{l.candidat.ville_residence}</td>
                      <td>
                        <div className="fw-semibold">{l.score_final} / 100</div>
                        {/* Dossier/entretien affichés SANS dénominateur : leurs maxima (65/35)
                            ne sont pas dans cette réponse API — les coder en dur reviendrait à
                            réintroduire un poids de barème (contrairement à `score_final`/100,
                            un plafond FIXE du système, jamais un poids de grille, cf. ServiceClassement::scoreFinal). */}
                        <div className="caption">Dossier {l.score_dossier ?? '—'} · Entretien {l.score_entretien ?? '—'}</div>
                        <DepartageBadges departage={l.departage} />
                      </td>
                      <td>
                        <span className={`badge ${DECISION_BADGE[l.decision] ?? 'badge-neutral'}`}>{DECISION_LABEL[l.decision] ?? l.decision}</span>
                        {l.non_eligible ? <div className="caption" style={{ marginTop: 4 }}>Non éligible</div> : null}
                      </td>
                      <td>
                        <div className="flex gap-2">
                          <Link to={evaluateurDossierPath(l.candidature_id)} className="btn btn-sm btn-outline">Voir</Link>
                          {!data.publie && l.decision !== 'retenu' ? (
                            <button type="button" className="btn btn-sm btn-outline" onClick={() => setMotifLigne(l)}>
                              Motif
                            </button>
                          ) : null}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}

      {calculating ? (
        <div className="text-center" style={{ marginTop: 'var(--space-4)' }}>
          <Spinner />
        </div>
      ) : null}

      {motifLigne ? (
        <MotifModal
          ligne={motifLigne}
          saving={savingMotif}
          error={error}
          onCancel={() => setMotifLigne(null)}
          onSave={enregistrerMotif}
        />
      ) : null}

      {confirmingPublish ? (
        <PublishConfirmDialog
          campagneNom={data.campagne.nom}
          loading={publishing}
          onCancel={() => setConfirmingPublish(false)}
          onConfirm={confirmerPublication}
        />
      ) : null}
    </AppShell>
  )
}
