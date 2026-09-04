import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth.js'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { Spinner } from '../../components/ui/Spinner.jsx'
import { evaluateurDossierPath, paths } from '../../routing/routes.js'
import { useDashboard } from './useDashboard.js'

function KpiCard({ label, value, icon, bg, fg }) {
  return (
    <div className="card kpi-card">
      <div className="kpi-head">
        <span className="caption">{label}</span>
        <span className="kpi-icon" style={{ background: bg, color: fg }}>
          <i className={`fa-solid ${icon}`} aria-hidden="true" />
        </span>
      </div>
      <div className="kpi-value">{value}</div>
    </div>
  )
}

function MiniListRow({ dossier, badge }) {
  return (
    <Link
      to={evaluateurDossierPath(dossier.id)}
      className="flex gap-3 items-center"
      style={{ textDecoration: 'none' }}
    >
      <span className="avatar avatar-sm">{(dossier.candidat?.prenom?.[0] || '') + (dossier.candidat?.nom?.[0] || '')}</span>
      <div style={{ flex: 1 }}>
        <div className="body-sm fw-medium" style={{ color: 'var(--casa-text-primary)' }}>
          {dossier.candidat?.prenom} {dossier.candidat?.nom}
        </div>
        <div className="caption">{dossier.filiere?.nom}{dossier.candidat?.ville_residence ? ` · ${dossier.candidat.ville_residence}` : ''}</div>
      </div>
      {badge}
    </Link>
  )
}

/**
 * Tableau de bord évaluateur (Lot 8c-1). KPI = `meta.total` de 4 appels filtrés
 * par `statut_interne` ; mini-listes = items déjà renvoyés par ces mêmes appels,
 * filtrés côté client sur des champs DÉJÀ calculés serveur (aucun recalcul).
 * Pas de graphiques (hors périmètre 8c-1, cf. Étape 1 Q1).
 */
export function EvaluateurDashboard() {
  const { user, role } = useAuth()
  const d = useDashboard()
  const prenom = user?.profil?.prenom || ''
  const estAdmin = role === 'administrateur'

  return (
    <AppShell title="Tableau de bord" space="evaluateur">
      <div className="page-head">
        <div>
          <h2>Bonjour {prenom} 👋</h2>
          <p className="text-muted">
            Voici la synthèse des dossiers {estAdmin ? 'du programme' : 'qui vous sont affectés'}.
          </p>
        </div>
        <Link to={paths.evaluateurDossiers} className="btn btn-primary">
          Traiter mes dossiers <i className="fa-solid fa-arrow-right" aria-hidden="true" />
        </Link>
      </div>

      {d.status === 'loading' ? (
        <div className="text-center" style={{ padding: 'var(--space-16)' }}>
          <Spinner large />
        </div>
      ) : d.status === 'error' ? (
        <Alert variant="warning">Le tableau de bord n'a pas pu être chargé. Réessayez plus tard.</Alert>
      ) : (
        <>
          <div className="grid grid-4" style={{ marginBottom: 'var(--space-6)' }}>
            <KpiCard label="Total dossiers" value={d.kpis.total} icon="fa-users" bg="var(--casa-primary-100)" fg="var(--casa-primary-700)" />
            <KpiCard label="À traiter" value={d.kpis.aTraiter} icon="fa-hourglass-half" bg="var(--casa-warning-100)" fg="var(--casa-warning-700)" />
            <KpiCard label="En instruction" value={d.kpis.enInstruction} icon="fa-magnifying-glass" bg="var(--casa-info-100)" fg="var(--casa-info-700)" />
            <KpiCard label="Évalués" value={d.kpis.evalues} icon="fa-circle-check" bg="var(--casa-success-100)" fg="var(--casa-success-700)" />
          </div>

          <div className="dashboard-layout">
            <div className="card">
              <h3 style={{ marginBottom: 'var(--space-4)' }}>Dossiers prioritaires (à traiter)</h3>
              {d.prioritaires.length === 0 ? (
                <p className="caption">Aucun dossier à traiter pour le moment.</p>
              ) : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
                  {d.prioritaires.map((c) => (
                    <MiniListRow key={c.id} dossier={c} badge={<span className="badge badge-info">Soumis</span>} />
                  ))}
                </div>
              )}
            </div>

            <div className="card">
              <h3 style={{ marginBottom: 'var(--space-4)' }}>Alertes d'éligibilité récentes</h3>
              {d.alertes.length === 0 ? (
                <p className="caption">Aucune alerte pour le moment.</p>
              ) : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
                  {d.alertes.map((c) => (
                    <MiniListRow key={c.id} dossier={c} badge={<span className="badge badge-danger">Non éligible</span>} />
                  ))}
                </div>
              )}
            </div>
          </div>
        </>
      )}
    </AppShell>
  )
}
