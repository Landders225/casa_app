import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth.js'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { Spinner } from '../../components/ui/Spinner.jsx'
import { apiClient } from '../../lib/apiClient.js'
import { formatDateFr } from '../../lib/formatDate.js'
import { evaluateurDossierPath } from '../../routing/routes.js'
import { ELIGIBILITE_LABELS, STATUT_INTERNE_LABELS } from './optionLabels.js'
import { useDossiers } from './useDossiers.js'

const TABS = [
  { value: '', label: 'Tous' },
  { value: 'soumis', label: 'À traiter' },
  { value: 'en_instruction', label: 'En instruction' },
  { value: 'evalue', label: 'Évalués' },
]

/**
 * Liste des dossiers — auto-scopée par le backend (`GET /evaluateur/candidatures`) :
 * mes dossiers pour un évaluateur, tous les dossiers instructibles pour un
 * administrateur (recouvrement ADR-10, même écran, même endpoint).
 */
export function DossiersList() {
  const { role } = useAuth()
  const [statutInterne, setStatutInterne] = useState('')
  const [filiere, setFiliere] = useState('')
  const [page, setPage] = useState(1)
  const [filieres, setFilieres] = useState([])
  const { status, items, meta } = useDossiers({ statutInterne, filiere, page })

  useEffect(() => {
    apiClient.get('/filieres').then((res) => setFilieres(res.data ?? [])).catch(() => {})
  }, [])

  const estAdmin = role === 'administrateur'
  const titre = estAdmin ? 'Tous les dossiers' : 'Mes dossiers'

  return (
    <AppShell title={titre} space="evaluateur">
      <div className="page-head">
        <div>
          <h2>{titre}</h2>
          <p className="text-muted">
            {estAdmin
              ? 'Vue d’ensemble de tous les dossiers instructibles (recouvrement administrateur).'
              : 'Candidatures qui vous sont affectées pour évaluation.'}
          </p>
        </div>
      </div>

      <div className="tabs" style={{ marginBottom: 'var(--space-5)' }}>
        {TABS.map((t) => (
          <button
            key={t.value || 'tous'}
            type="button"
            className={`tab${statutInterne === t.value ? ' is-active' : ''}`}
            onClick={() => {
              setStatutInterne(t.value)
              setPage(1)
            }}
          >
            {t.label}
          </button>
        ))}
      </div>

      <div className="form-group" style={{ maxWidth: 280, marginBottom: 'var(--space-5)' }}>
        <label className="label" htmlFor="filtre-filiere">Filière</label>
        <select
          id="filtre-filiere"
          className="select"
          value={filiere}
          onChange={(e) => {
            setFiliere(e.target.value)
            setPage(1)
          }}
        >
          <option value="">Toutes les filières</option>
          {filieres.map((f) => (
            <option key={f.id} value={f.code}>{f.nom}</option>
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
            <i className="fa-solid fa-folder-open" aria-hidden="true" />
          </div>
          <p>{estAdmin ? 'Aucun dossier' : 'Aucun dossier ne vous est affecté'} pour ce filtre.</p>
        </div>
      ) : (
        <>
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th>Candidat</th>
                  <th>Filière</th>
                  <th>Ville</th>
                  <th>Statut</th>
                  <th>Éligibilité</th>
                  <th>Vérification</th>
                  <th>Date</th>
                  <th aria-hidden="true" />
                </tr>
              </thead>
              <tbody>
                {items.map((c) => {
                  const statutInfo = STATUT_INTERNE_LABELS[c.statut_interne] ?? { label: c.statut_interne, badge: 'badge-neutral' }
                  const eligInfo = ELIGIBILITE_LABELS[c.statut_eligibilite_interne] ?? { label: c.statut_eligibilite_interne, badge: 'badge-neutral' }
                  return (
                    <tr key={c.id}>
                      <td>
                        <div className="flex items-center gap-3">
                          <span className="avatar avatar-sm">{(c.candidat?.prenom?.[0] || '') + (c.candidat?.nom?.[0] || '')}</span>
                          <div>
                            <div className="fw-medium body-sm">{c.candidat?.prenom} {c.candidat?.nom}</div>
                            <div className="caption">{c.numero_dossier}</div>
                          </div>
                        </div>
                      </td>
                      <td>{c.filiere?.nom}</td>
                      <td>{c.candidat?.ville_residence}</td>
                      <td><span className={`badge ${statutInfo.badge}`}>{statutInfo.label}</span></td>
                      <td><span className={`badge ${eligInfo.badge}`}>{eligInfo.label}</span></td>
                      <td>{c.verification_faite ? 'Oui' : '—'}</td>
                      <td>{formatDateFr(c.date_soumission)}</td>
                      <td><Link to={evaluateurDossierPath(c.id)} className="btn btn-sm btn-outline">Voir</Link></td>
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
              <button
                type="button"
                className="page-btn"
                disabled={page >= meta.last_page}
                onClick={() => setPage((p) => p + 1)}
              >
                <i className="fa-solid fa-chevron-right" aria-hidden="true" />
              </button>
            </div>
          ) : null}
        </>
      )}
    </AppShell>
  )
}
