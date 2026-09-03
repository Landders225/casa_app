import { useEffect, useState } from 'react'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { Spinner } from '../../components/ui/Spinner.jsx'
import { useAuth } from '../../auth/useAuth.js'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

const STATUT_LABEL = {
  brouillon: { label: 'Brouillon', badge: 'badge-neutral' },
  en_cours_de_traitement: { label: 'En cours de traitement', badge: 'badge-info' },
  decision_publiee: { label: 'Décision publiée', badge: 'badge-primary' },
}

const PARCOURS = ['Compte créé', 'Dossier soumis', 'Instruction', 'Entretien', 'Résultat']

/**
 * Tableau de bord candidat — version MINIMALE (Lot 8b-1).
 * Consomme GET /api/candidature (404 = pas encore de candidature -> état d'accueil).
 * Le formulaire de candidature arrive au Lot 8b-2, le suivi détaillé au 8b-3.
 */
export function CandidatDashboard() {
  const { user } = useAuth()
  const [state, setState] = useState({ status: 'loading', candidature: null })

  useEffect(() => {
    let alive = true
    apiClient
      .get('/candidature')
      .then((res) => {
        if (alive) setState({ status: 'ready', candidature: res.data })
      })
      .catch((err) => {
        if (alive) {
          // 404 = aucune candidature (cas normal après inscription).
          if (err instanceof ApiError && err.status === 404) {
            setState({ status: 'ready', candidature: null })
          } else {
            setState({ status: 'error', candidature: null })
          }
        }
      })
    return () => {
      alive = false
    }
  }, [])

  const prenom = user?.profil?.prenom || ''
  const candidature = state.candidature
  const activeStep = candidature ? (candidature.statut_public === 'brouillon' ? 1 : 2) : 1

  return (
    <AppShell title="Tableau de bord">
      <div className="page-head">
        <div>
          <h2>Bonjour {prenom} 👋</h2>
          <p className="text-muted">Voici l'état d'avancement de votre parcours CASA.</p>
        </div>
      </div>

      {state.status === 'loading' ? (
        <div className="text-center" style={{ padding: 'var(--space-16)' }}>
          <Spinner large />
        </div>
      ) : state.status === 'error' ? (
        <Alert variant="warning">Votre tableau de bord n'a pas pu être chargé. Réessayez plus tard.</Alert>
      ) : (
        <>
          {!candidature ? (
            <div style={{ marginBottom: 'var(--space-6)' }}>
              <Alert variant="success" title="Votre compte a été créé !">
                Vous pouvez maintenant préparer votre dossier de candidature.
              </Alert>
            </div>
          ) : null}

          <div className="dashboard-layout">
            <div className="card">
              <div className="card-header">
                <h3>Prochaine étape</h3>
              </div>
              <div
                className="flex gap-4 items-center"
                style={{ padding: 'var(--space-4)', background: 'var(--casa-primary-50)', borderRadius: 'var(--radius-lg)' }}
              >
                <span
                  className="kpi-icon"
                  style={{ width: 56, height: 56, fontSize: '1.3rem', background: 'var(--casa-primary-600)', color: '#fff', flexShrink: 0 }}
                >
                  <i className="fa-solid fa-flag" aria-hidden="true" />
                </span>
                <div style={{ flex: 1 }}>
                  <div className="fw-semibold body-lg">
                    {candidature ? 'Suivre ma candidature' : 'Compléter mon dossier'}
                  </div>
                  <div className="text-muted body-sm">
                    {candidature
                      ? 'Votre dossier suit son cours. Le résultat vous sera communiqué à l\'issue du processus de sélection.'
                      : 'Le formulaire de candidature (choix de la filière, informations, justificatifs) sera disponible très prochainement.'}
                  </div>
                </div>
                <button type="button" className="btn btn-primary" disabled>
                  {candidature ? 'Suivre' : 'Bientôt disponible'}
                </button>
              </div>

              <h4 style={{ margin: 'var(--space-8) 0 var(--space-4)' }}>Mon parcours de candidature</h4>
              <div className="stepper">
                {PARCOURS.map((label, i) => {
                  const done = i + 1 < activeStep
                  const isActive = i + 1 === activeStep
                  return (
                    <div className={`step${done ? ' is-complete' : isActive ? ' is-active' : ''}`} key={label}>
                      <div className="step-circle">
                        {done ? <i className="fa-solid fa-check" aria-hidden="true" /> : i + 1}
                      </div>
                      <span className="step-label">{label}</span>
                      {i < PARCOURS.length - 1 ? <div className="step-line" /> : null}
                    </div>
                  )
                })}
              </div>
            </div>

            <div className="card">
              <div className="card-header">
                <h3>Ma candidature</h3>
              </div>
              {candidature ? (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
                  <div>
                    <span
                      className={`badge badge-lg ${STATUT_LABEL[candidature.statut_public]?.badge ?? 'badge-neutral'}`}
                    >
                      {STATUT_LABEL[candidature.statut_public]?.label ?? candidature.statut_public}
                    </span>
                  </div>
                  <div className="caption">N° {candidature.numero_dossier}</div>
                  {candidature.filiere ? (
                    <div className="body-sm">
                      Filière : <span className="fw-semibold">{candidature.filiere.nom}</span>
                    </div>
                  ) : null}
                </div>
              ) : (
                <div className="empty-state" style={{ padding: 'var(--space-8) var(--space-4)' }}>
                  <div className="empty-icon">
                    <i className="fa-solid fa-file-lines" aria-hidden="true" />
                  </div>
                  <p>Vous n'avez pas encore de dossier de candidature.</p>
                </div>
              )}
            </div>
          </div>
        </>
      )}
    </AppShell>
  )
}
