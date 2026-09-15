import { useState } from 'react'
import { Link } from 'react-router-dom'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { Spinner } from '../../components/ui/Spinner.jsx'
import { formatDateFr } from '../../lib/formatDate.js'
import { adminClassementPath } from '../../routing/routes.js'
import { CampagneFormModal } from './CampagneFormModal.jsx'
import { ConfirmDialog } from './ConfirmDialog.jsx'
import { ModifierCampagneModal } from './ModifierCampagneModal.jsx'
import { useQuotas } from './useQuotas.js'

const BADGE = { ouverte: 'badge-success', brouillon: 'badge-neutral', cloturee: 'badge-danger' }
const LABEL = { ouverte: 'Ouverte', brouillon: 'Brouillon', cloturee: 'Clôturée' }

/**
 * Quotas & campagnes (Lot 17, D-6a-2) — création d'une campagne + édition
 * nom/dates/quotas. Écran DISTINCT de `Campagnes.jsx` (transitions d'état
 * ouvrir/clôturer, inchangé) : deux natures d'action différentes, pas
 * fusionnées (Étape 1, Q5). Active l'entrée de nav « Quotas », jusque-là
 * inerte (maquette).
 *
 * Le garde-fou central (Étape 1, Q2) est 100 % serveur et REFLÉTÉ tel quel,
 * jamais deviné : `classement_perime` (renvoyé par l'API) bloque l'affichage
 * normal du panneau de quotas derrière un avertissement bloquant, exactement
 * comme `publiee` verrouille tout le panneau. Le formulaire de quotas ne
 * PROPOSE la sauvegarde qu'après confirmation explicite quand un classement
 * existe déjà (la modification va le périmer).
 */
export function Quotas() {
  const { status, items, saving, creating, error, creer, modifierInfos, modifierQuotas } = useQuotas()
  const [showCreation, setShowCreation] = useState(false)
  const [edition, setEdition] = useState(null) // campagne
  const [confirmPeremption, setConfirmPeremption] = useState(null) // { campagne, quotas }
  const [brouillons, setBrouillons] = useState({}) // { [campagneId]: { [filiereId]: string } }

  const brouillonDe = (campagne) =>
    brouillons[campagne.id] ?? Object.fromEntries(campagne.filieres.map((f) => [f.id, String(f.quota)]))

  const changerBrouillon = (campagne, filiereId, valeur) => {
    setBrouillons((b) => ({
      ...b,
      [campagne.id]: { ...brouillonDe(campagne), [filiereId]: valeur },
    }))
  }

  const enregistrerQuotas = async (campagne) => {
    const brouillon = brouillonDe(campagne)
    const quotas = campagne.filieres.map((f) => ({ filiere_id: f.id, quota: Number(brouillon[f.id]) || 0 }))

    if (campagne.classement_calcule) {
      setConfirmPeremption({ campagne, quotas })
      return
    }
    await modifierQuotas(campagne.id, quotas)
  }

  const confirmerPeremption = async () => {
    const { campagne, quotas } = confirmPeremption
    try {
      await modifierQuotas(campagne.id, quotas)
    } finally {
      setConfirmPeremption(null)
    }
  }

  return (
    <AppShell title="Quotas">
      <div className="page-head">
        <div>
          <h2>Quotas & campagnes</h2>
          <p className="text-muted">Créer une campagne, ajuster son nom, ses dates et le quota de chaque filière.</p>
        </div>
        <button type="button" className="btn btn-primary btn-sm" onClick={() => setShowCreation(true)}>
          <i className="fa-solid fa-plus" aria-hidden="true" /> Créer une campagne
        </button>
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
              <div className="grid grid-2" style={{ margin: 'var(--space-4) 0' }}>
                <div>
                  <div className="caption">Ouverture</div>
                  <div className="fw-semibold">{formatDateFr(c.date_ouverture)}</div>
                </div>
                <div>
                  <div className="caption">Clôture</div>
                  <div className="fw-semibold">{formatDateFr(c.date_cloture)}</div>
                </div>
              </div>

              <div className="flex gap-2" style={{ marginBottom: 'var(--space-4)' }}>
                <button type="button" className="btn btn-outline btn-sm" onClick={() => setEdition(c)}>
                  Modifier le nom / les dates
                </button>
                <Link to={adminClassementPath(c.id)} className="btn btn-outline btn-sm">
                  Voir le classement
                </Link>
              </div>

              {c.publiee ? (
                <Alert variant="warning">
                  Résultats publiés : les quotas de cette campagne sont définitivement verrouillés.
                </Alert>
              ) : c.classement_perime ? (
                <Alert variant="danger" title="Classement périmé">
                  Un quota a été modifié depuis le dernier calcul du classement : les décisions affichées sur l'écran
                  Classement ne reflètent plus les quotas actuels. Recalculez le classement avant toute publication.
                </Alert>
              ) : c.classement_calcule ? (
                <Alert variant="warning">
                  Un classement a déjà été calculé pour cette campagne. Modifier un quota le marquera périmé — un
                  recalcul explicite sera nécessaire.
                </Alert>
              ) : null}

              <p className="label" style={{ marginTop: 'var(--space-3)' }}>Quotas par filière</p>
              <div className="grid" style={{ gap: 'var(--space-2)' }}>
                {c.filieres.map((f) => (
                  <div key={f.id} className="flex items-center gap-3">
                    <span className="body-sm" style={{ flex: 1 }}>{f.nom}</span>
                    <input
                      type="number"
                      className="input"
                      style={{ width: '5rem' }}
                      min="0"
                      disabled={c.publiee || saving === c.id}
                      value={brouillonDe(c)[f.id] ?? ''}
                      onChange={(e) => changerBrouillon(c, f.id, e.target.value)}
                      aria-label={`Quota ${f.nom} — ${c.nom}`}
                    />
                  </div>
                ))}
              </div>

              {!c.publiee ? (
                <button
                  type="button"
                  className="btn btn-primary btn-sm"
                  style={{ marginTop: 'var(--space-3)' }}
                  disabled={saving === c.id}
                  onClick={() => enregistrerQuotas(c)}
                >
                  {saving === c.id ? 'Enregistrement…' : 'Enregistrer les quotas'}
                </button>
              ) : null}
            </div>
          ))}
        </div>
      )}

      {showCreation ? (
        <CampagneFormModal
          saving={creating}
          onCancel={() => setShowCreation(false)}
          onSubmit={async (payload) => {
            await creer(payload)
            setShowCreation(false)
          }}
        />
      ) : null}

      {edition ? (
        <ModifierCampagneModal
          campagne={edition}
          saving={saving === edition.id}
          onCancel={() => setEdition(null)}
          onSubmit={async (payload) => {
            await modifierInfos(edition.id, payload)
            setEdition(null)
          }}
        />
      ) : null}

      {confirmPeremption ? (
        <ConfirmDialog
          title="Périmer le classement existant ?"
          message={`Un classement a déjà été calculé pour « ${confirmPeremption.campagne.nom} ». Enregistrer ce nouveau quota le marquera périmé (bloque toute publication) jusqu'à un recalcul explicite.`}
          confirmLabel="Modifier le quota"
          danger
          loading={saving === confirmPeremption.campagne.id}
          onCancel={() => setConfirmPeremption(null)}
          onConfirm={confirmerPeremption}
        />
      ) : null}
    </AppShell>
  )
}
