import { Link } from 'react-router-dom'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { Spinner } from '../../components/ui/Spinner.jsx'
import { useAuth } from '../../auth/useAuth.js'
import { paths } from '../../routing/routes.js'
import { useMaCandidature } from './useMaCandidature.js'
import { SUIVI_ETAPES, suiviEtapeCourante } from './resultatMessages.js'

const STATUT_BADGE = {
  brouillon: { label: 'Brouillon', badge: 'badge-neutral' },
  en_cours_de_traitement: { label: 'En cours de traitement', badge: 'badge-info' },
  decision_publiee: { label: 'Décision publiée', badge: 'badge-primary' },
}

/**
 * Bloc « Prochaine étape » — dérivé UNIQUEMENT de `statut_public` (ou de
 * l'absence de candidature). Aucun état interne, aucune notion d'éligibilité.
 */
function prochaineEtape(status, statutPublic) {
  if (status === 'none') {
    return {
      titre: 'Compléter mon dossier',
      desc: 'Formulaire guidé : filière, profil, expériences, justificatifs, puis soumission.',
      to: paths.candidatureWizard,
      cta: 'Commencer',
    }
  }
  switch (statutPublic) {
    case 'brouillon':
      return {
        titre: 'Reprendre mon dossier',
        desc: 'Votre brouillon est enregistré. Reprenez la saisie là où vous vous êtes arrêté(e).',
        to: paths.candidatureWizard,
        cta: 'Reprendre',
      }
    case 'decision_publiee':
      return {
        titre: 'Votre résultat est disponible',
        desc: 'Les résultats ont été publiés — consultez la décision relative à votre candidature.',
        to: paths.maCandidature,
        cta: 'Voir mon résultat',
      }
    case 'en_cours_de_traitement':
    default:
      return {
        titre: 'Candidature en cours de traitement',
        desc: "Votre dossier suit son cours. Le résultat vous sera communiqué à l'issue du processus de sélection.",
        to: paths.maCandidature,
        cta: 'Suivre ma candidature',
      }
  }
}

/**
 * Tableau de bord candidat (Lot 8b-3).
 * Consomme `GET /api/candidature` via `useMaCandidature` : rendu conditionnel
 * par `statut_public` seul. Sobre — pas de barre de progression en %
 * (le stepper communique déjà l'avancement, sans faux-semblant de précision).
 */
export function CandidatDashboard() {
  const { user } = useAuth()
  const s = useMaCandidature()

  const prenom = user?.profil?.prenom || ''

  if (s.status === 'loading') {
    return (
      <AppShell title="Tableau de bord">
        <div className="text-center" style={{ padding: 'var(--space-16)' }}>
          <Spinner large />
        </div>
      </AppShell>
    )
  }

  if (s.status === 'error') {
    return (
      <AppShell title="Tableau de bord">
        <Alert variant="warning">Votre tableau de bord n'a pas pu être chargé. Réessayez plus tard.</Alert>
      </AppShell>
    )
  }

  const aucuneCandidature = s.status === 'none'
  const next = prochaineEtape(s.status, s.statutPublic)
  const badge = aucuneCandidature ? null : STATUT_BADGE[s.statutPublic] ?? { label: s.statutPublic, badge: 'badge-neutral' }
  const etapeCourante = aucuneCandidature ? 1 : suiviEtapeCourante(s.statutPublic)

  return (
    <AppShell title="Tableau de bord">
      <div className="page-head">
        <div>
          <h2>Bonjour {prenom} 👋</h2>
          <p className="text-muted">Voici l'état d'avancement de votre parcours CASA.</p>
        </div>
      </div>

      {aucuneCandidature ? (
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
              <div className="fw-semibold body-lg">{next.titre}</div>
              <div className="text-muted body-sm">{next.desc}</div>
            </div>
            <Link to={next.to} className="btn btn-primary">
              {next.cta}
            </Link>
          </div>

          <h4 style={{ margin: 'var(--space-8) 0 var(--space-4)' }}>Mon parcours de candidature</h4>
          <div className="stepper">
            {SUIVI_ETAPES.map((label, i) => {
              const done = i < etapeCourante
              const isActive = i === etapeCourante
              return (
                <div className={`step${done ? ' is-complete' : isActive ? ' is-active' : ''}`} key={label}>
                  <div className="step-circle">
                    {done ? <i className="fa-solid fa-check" aria-hidden="true" /> : i + 1}
                  </div>
                  <span className="step-label">{label}</span>
                  {i < SUIVI_ETAPES.length - 1 ? <div className="step-line" /> : null}
                </div>
              )
            })}
          </div>
        </div>

        <div className="card">
          <div className="card-header">
            <h3>Ma candidature</h3>
          </div>
          {aucuneCandidature ? (
            <div className="empty-state" style={{ padding: 'var(--space-8) var(--space-4)' }}>
              <div className="empty-icon">
                <i className="fa-solid fa-file-lines" aria-hidden="true" />
              </div>
              <p>Vous n'avez pas encore de dossier de candidature.</p>
            </div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
              <div>
                <span className={`badge badge-lg ${badge.badge}`}>{badge.label}</span>
              </div>
              <div className="caption">N° {s.numeroDossier}</div>
              {s.filiereNom ? (
                <div className="body-sm">
                  Filière : <span className="fw-semibold">{s.filiereNom}</span>
                </div>
              ) : null}
              <div className="body-sm">
                Documents : <span className="fw-semibold">{s.piecesCount} / 6</span>
              </div>
              <Link
                to={paths.maCandidature}
                className="btn btn-outline btn-sm"
                style={{ marginTop: 'var(--space-3)', alignSelf: 'flex-start' }}
              >
                {s.statutPublic === 'decision_publiee' ? 'Voir mon résultat' : 'Suivre ma candidature'}
              </Link>
            </div>
          )}
        </div>
      </div>
    </AppShell>
  )
}
