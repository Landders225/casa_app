import { useState } from 'react'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { Spinner } from '../../components/ui/Spinner.jsx'
import { formatDateFr } from '../../lib/formatDate.js'
import { useNotifications } from './useNotifications.js'

/**
 * Historique in-app des notifications (Lot 12c) — canal `database` des 4
 * Notifications du Lot 12b (ADR-33). Contenu déjà neutre côté serveur
 * (`toDatabase()`) : cet écran l'affiche tel quel, ne recompose rien.
 *
 * Clic sur une ligne NON LUE = la marque lue (pas de bouton dédié par ligne,
 * meilleure ergonomie — validé Étape 1 Q3). Aucune navigation déclenchée : le
 * lien porté par chaque notification (`/candidat`) est toujours générique,
 * inutile de rediriger depuis un écran qui EST déjà l'espace candidat.
 */
const ICONES = {
  inscription: 'fa-user-plus',
  soumission: 'fa-file-lines',
  entretien: 'fa-comments',
  resultats: 'fa-ranking-star',
}

export function Notifications() {
  const [page, setPage] = useState(1)
  const { status, items, meta, marquerLue, marquerToutLu } = useNotifications({ page })
  const [marquageEnCours, setMarquageEnCours] = useState(false)

  const nonLues = items.filter((n) => !n.lue).length

  const handleMarquerToutLu = async () => {
    setMarquageEnCours(true)
    try {
      await marquerToutLu()
    } finally {
      setMarquageEnCours(false)
    }
  }

  const handleLigne = (n) => {
    if (!n.lue) marquerLue(n.id)
  }

  return (
    <AppShell title="Notifications">
      <div className="page-head">
        <div>
          <h2>Notifications</h2>
          <p className="text-muted">Historique de vos notifications CASA.</p>
        </div>
        {items.length > 0 ? (
          <button
            type="button"
            className={`btn btn-secondary${marquageEnCours ? ' is-loading' : ''}`}
            disabled={marquageEnCours || nonLues === 0}
            onClick={handleMarquerToutLu}
          >
            Tout marquer comme lu
          </button>
        ) : null}
      </div>

      {status === 'loading' ? (
        <div className="text-center" style={{ padding: 'var(--space-16)' }}>
          <Spinner large />
        </div>
      ) : status === 'error' ? (
        <Alert variant="warning">L'historique n'a pas pu être chargé. Réessayez plus tard.</Alert>
      ) : items.length === 0 ? (
        <div className="empty-state card">
          <i className="fa-solid fa-bell" aria-hidden="true" style={{ fontSize: '1.5rem', color: 'var(--casa-text-secondary)' }} />
          <p>Aucune notification pour le moment.</p>
        </div>
      ) : (
        <>
          <div className="card" style={{ padding: 0 }}>
            <ul className="notifications-list" style={{ listStyle: 'none', margin: 0, padding: 0 }}>
              {items.map((n) => (
                <li key={n.id}>
                  <button
                    type="button"
                    onClick={() => handleLigne(n)}
                    className="notification-row"
                    style={{
                      display: 'flex', alignItems: 'flex-start', gap: 'var(--space-3)',
                      width: '100%', textAlign: 'left', padding: 'var(--space-4)',
                      border: 'none', borderBottom: '1px solid var(--casa-border)',
                      background: n.lue ? 'transparent' : 'var(--casa-bg-alt)',
                      cursor: n.lue ? 'default' : 'pointer',
                    }}
                  >
                    <i
                      className={`fa-solid ${ICONES[n.categorie] ?? 'fa-bell'}`}
                      aria-hidden="true"
                      style={{ color: 'var(--casa-primary-600)', marginTop: '3px' }}
                    />
                    <div style={{ flex: 1 }}>
                      <div className="flex items-center gap-2">
                        <span className="fw-medium">{n.titre}</span>
                        {!n.lue ? <span className="badge badge-danger">Non lu</span> : null}
                      </div>
                      <p className="text-muted" style={{ margin: 'var(--space-1) 0 0' }}>{n.message}</p>
                      <p className="caption" style={{ margin: 'var(--space-1) 0 0' }}>{formatDateFr(n.creee_le)}</p>
                    </div>
                  </button>
                </li>
              ))}
            </ul>
          </div>

          {meta && meta.last_page > 1 ? (
            <div className="flex justify-between items-center" style={{ marginTop: 'var(--space-4)' }}>
              <span className="caption">Page {meta.current_page} / {meta.last_page} · {meta.total} notifications</span>
              <div className="pagination">
                <button type="button" className="page-btn" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                  <i className="fa-solid fa-chevron-left" aria-hidden="true" />
                </button>
                <button type="button" className="page-btn" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>
                  <i className="fa-solid fa-chevron-right" aria-hidden="true" />
                </button>
              </div>
            </div>
          ) : null}
        </>
      )}
    </AppShell>
  )
}
