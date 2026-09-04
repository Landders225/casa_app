import { useState } from 'react'
import { Link } from 'react-router-dom'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { Spinner } from '../../components/ui/Spinner.jsx'
import { formatDateFr } from '../../lib/formatDate.js'
import { adminClassementPath } from '../../routing/routes.js'
import { ConfirmDialog } from './ConfirmDialog.jsx'
import { useCampagnes } from './useCampagnes.js'

const BADGE = { ouverte: 'badge-success', brouillon: 'badge-neutral', cloturee: 'badge-danger' }
const LABEL = { ouverte: 'Ouverte', brouillon: 'Brouillon', cloturee: 'Clôturée' }

/**
 * Campagnes — liste + transitions d'état (Lot 8d-1). `GET /admin/campagnes`
 * est le nouvel endpoint (Étape 1, Q1) ; les boutons proposés dépendent
 * UNIQUEMENT de `statut` renvoyé par l'API (aucune règle de transition
 * devinée côté client). Le garde-fou « une seule campagne ouverte » et
 * l'irréversibilité de la clôture restent 100 % serveur (409/422 affichés
 * tels quels). Pas de création (D-6a-2, hors backend) : pas de bouton
 * « Nouvelle campagne ».
 */
export function Campagnes() {
  const { status, items, changing, error, changerStatut } = useCampagnes()
  const [confirming, setConfirming] = useState(null) // { id, nom, cible }

  const executer = async () => {
    const { id, cible } = confirming
    try {
      await changerStatut(id, cible)
      setConfirming(null)
    } catch {
      // Le message d'erreur (409/422 verbatim) reste affiché ; on referme le
      // dialogue pour laisser voir l'alerte sous la liste.
      setConfirming(null)
    }
  }

  return (
    <AppShell title="Campagnes">
      <div className="page-head">
        <div>
          <h2>Campagnes de candidature</h2>
          <p className="text-muted">Ouverture, clôture et suivi des cohortes du dispositif CASA.</p>
        </div>
      </div>

      {error ? (
        <div style={{ marginBottom: 'var(--space-5)' }}>
          <Alert variant="danger">{error}</Alert>
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
          <p>Aucune campagne pour le moment.</p>
        </div>
      ) : (
        <div className="grid grid-2">
          {items.map((c) => (
            <div className="card" key={c.id}>
              <div className="card-header">
                <h3>{c.nom}</h3>
                <span className={`badge ${BADGE[c.statut] ?? 'badge-neutral'} badge-lg`}>{LABEL[c.statut] ?? c.statut}</span>
              </div>
              <div className="grid grid-2" style={{ margin: 'var(--space-5) 0' }}>
                <div>
                  <div className="caption">Ouverture</div>
                  <div className="fw-semibold">{formatDateFr(c.date_ouverture)}</div>
                </div>
                <div>
                  <div className="caption">Clôture</div>
                  <div className="fw-semibold">{formatDateFr(c.date_cloture)}</div>
                </div>
                <div>
                  <div className="caption">Places prévues</div>
                  <div className="fw-semibold">{c.places_totales}</div>
                </div>
              </div>
              <div className="flex gap-2">
                {c.statut === 'brouillon' ? (
                  <button
                    type="button"
                    className="btn btn-primary btn-sm"
                    disabled={changing === c.id}
                    onClick={() => setConfirming({ id: c.id, nom: c.nom, cible: 'ouverte' })}
                  >
                    Ouvrir la campagne
                  </button>
                ) : null}
                {c.statut === 'ouverte' ? (
                  <button
                    type="button"
                    className="btn btn-danger btn-sm"
                    disabled={changing === c.id}
                    onClick={() => setConfirming({ id: c.id, nom: c.nom, cible: 'cloturee' })}
                  >
                    Clôturer la campagne
                  </button>
                ) : null}
                <Link to={adminClassementPath(c.id)} className="btn btn-outline btn-sm">
                  Voir le classement
                </Link>
              </div>
            </div>
          ))}
        </div>
      )}

      {confirming ? (
        <ConfirmDialog
          title={confirming.cible === 'ouverte' ? `Ouvrir « ${confirming.nom} » ?` : `Clôturer « ${confirming.nom} » ?`}
          message={
            confirming.cible === 'ouverte'
              ? "Les candidats pourront déposer leur dossier dès l'ouverture."
              : 'Aucune nouvelle candidature ne pourra être déposée après clôture. Cette transition est définitive.'
          }
          confirmLabel={confirming.cible === 'ouverte' ? 'Ouvrir' : 'Clôturer'}
          danger={confirming.cible === 'cloturee'}
          loading={changing === confirming.id}
          onCancel={() => setConfirming(null)}
          onConfirm={executer}
        />
      ) : null}
    </AppShell>
  )
}
