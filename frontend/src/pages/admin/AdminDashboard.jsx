import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth.js'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { Spinner } from '../../components/ui/Spinner.jsx'
import { formatDateFr } from '../../lib/formatDate.js'
import { paths } from '../../routing/routes.js'
import { useDashboardAdmin } from './useDashboardAdmin.js'

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

const CAMPAGNE_BADGE = { ouverte: 'badge-success', brouillon: 'badge-neutral', cloturee: 'badge-danger' }
const CAMPAGNE_LABEL = { ouverte: 'Ouverte', brouillon: 'Brouillon', cloturee: 'Clôturée' }

/**
 * Tableau de bord administrateur (Lot 8d-1). 4 KPI = `meta.total` de 4 appels
 * `GET /admin/candidatures` filtrés (aucun calcul reconstitué) + une carte
 * Campagnes (liste depuis le nouvel endpoint). Pas de graphique (D-8c1-1,
 * même choix qu'au 8c-1) : aucun taux d'éligibilité/sélection n'est
 * dérivable des filtres exposés par le backend.
 */
export function AdminDashboard() {
  const { user } = useAuth()
  const d = useDashboardAdmin()
  const prenom = user?.profil?.prenom || ''

  return (
    <AppShell title="Tableau de bord">
      <div className="page-head">
        <div>
          <h2>Bonjour {prenom} 👋</h2>
          <p className="text-muted">Vue d'ensemble du pilotage de la sélection CASA.</p>
        </div>
        <Link to={paths.adminCampagnes} className="btn btn-primary">
          Gérer les campagnes <i className="fa-solid fa-arrow-right" aria-hidden="true" />
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
            <KpiCard label="Candidatures" value={d.kpis.total} icon="fa-address-card" bg="var(--casa-primary-100)" fg="var(--casa-primary-700)" />
            <KpiCard label="Non affectées" value={d.kpis.nonAffectes} icon="fa-user-clock" bg="var(--casa-warning-100)" fg="var(--casa-warning-700)" />
            <KpiCard label="En instruction" value={d.kpis.enInstruction} icon="fa-magnifying-glass" bg="var(--casa-info-100)" fg="var(--casa-info-700)" />
            <KpiCard label="Évaluées" value={d.kpis.evalues} icon="fa-clipboard-check" bg="var(--casa-accent-100)" fg="var(--casa-accent-700)" />
          </div>

          <div className="card" style={{ maxWidth: 560 }}>
            <h3 style={{ marginBottom: 'var(--space-4)' }}>Campagnes</h3>
            {d.campagnes.length === 0 ? (
              <p className="caption">Aucune campagne pour le moment.</p>
            ) : (
              d.campagnes.map((c) => (
                <div key={c.id} className="flex items-center gap-3" style={{ padding: 'var(--space-3) 0', borderBottom: '1px solid var(--casa-border)' }}>
                  <div className="kpi-icon" style={{ background: 'var(--casa-primary-100)', color: 'var(--casa-primary-700)' }}>
                    <i className="fa-solid fa-calendar-check" aria-hidden="true" />
                  </div>
                  <div style={{ flex: 1 }}>
                    <div className="fw-medium body-sm">{c.nom}</div>
                    <div className="caption">{formatDateFr(c.date_ouverture)} → {formatDateFr(c.date_cloture)}</div>
                  </div>
                  <span className={`badge ${CAMPAGNE_BADGE[c.statut] ?? 'badge-neutral'}`}>{CAMPAGNE_LABEL[c.statut] ?? c.statut}</span>
                </div>
              ))
            )}
            <Link to={paths.adminCampagnes} className="btn btn-outline btn-sm btn-block" style={{ marginTop: 'var(--space-4)' }}>
              Gérer les campagnes
            </Link>
          </div>
        </>
      )}
    </AppShell>
  )
}
