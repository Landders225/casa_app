import { useState } from 'react'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { Spinner } from '../../components/ui/Spinner.jsx'
import { useAuth } from '../../auth/useAuth.js'
import { ApiError } from '../../lib/ApiError.js'
import { formatDateFr } from '../../lib/formatDate.js'
import { ConfirmDialog } from './ConfirmDialog.jsx'
import { MembreFormModal } from './MembreFormModal.jsx'
import { MotDePasseDialog } from './MotDePasseDialog.jsx'
import { ROLE_LABELS } from './optionLabels.js'
import { useEquipe } from './useEquipe.js'

/**
 * Équipe (Lot 11b) — gestion des comptes évaluateurs + administrateurs.
 * PAS les candidats (D-11b-1). Quatre actes, tous 100 % serveur pour les
 * règles : créer, (dés)activer (garde-fous G1/G2 → 422 verbatim), réinitialiser
 * le mot de passe. Le rôle d'un compte est immuable ; on ne supprime pas, on
 * désactive.
 *
 * Le bouton « Désactiver » de SA PROPRE ligne est neutralisé côté client — un
 * simple reflet de G1 (le serveur refuse de toute façon) pour ne pas proposer
 * une action vouée à l'échec.
 */
export function Equipe() {
  const { user } = useAuth()
  const { status, items, busyId, creating, creer, definirActivation, reinitialiserMotDePasse } = useEquipe()

  const [showForm, setShowForm] = useState(false)
  const [confirmActivation, setConfirmActivation] = useState(null) // { membre, actif }
  const [confirmReset, setConfirmReset] = useState(null) // membre
  const [motDePasse, setMotDePasse] = useState(null) // { titre, email, valeur }
  const [erreur, setErreur] = useState(null)

  const soumettreCreation = async (form) => {
    const { motDePasse: valeur, membre } = await creer(form)
    setShowForm(false)
    setMotDePasse({ titre: 'Compte créé', email: membre.email, valeur })
  }

  const executerActivation = async () => {
    const { membre, actif } = confirmActivation
    setErreur(null)
    try {
      await definirActivation(membre.id, actif)
      setConfirmActivation(null)
    } catch (err) {
      // 422 G1/G2 (ou autre) : message affiché VERBATIM sous la liste.
      setErreur(err instanceof ApiError ? err.message : "L'opération a échoué.")
      setConfirmActivation(null)
    }
  }

  const executerReset = async () => {
    const membre = confirmReset
    setErreur(null)
    try {
      const valeur = await reinitialiserMotDePasse(membre.id)
      setConfirmReset(null)
      setMotDePasse({ titre: 'Mot de passe réinitialisé', email: membre.email, valeur })
    } catch (err) {
      setErreur(err instanceof ApiError ? err.message : "L'opération a échoué.")
      setConfirmReset(null)
    }
  }

  return (
    <AppShell title="Équipe">
      <div className="page-head">
        <div>
          <h2>Équipe du projet</h2>
          <p className="text-muted">Comptes des évaluateurs du jury et des administrateurs.</p>
        </div>
        <button type="button" className="btn btn-primary btn-sm" onClick={() => setShowForm(true)}>
          <i className="fa-solid fa-user-plus" aria-hidden="true" /> Ajouter un membre
        </button>
      </div>

      {erreur ? (
        <div style={{ marginBottom: 'var(--space-5)' }}>
          <Alert variant="danger">{erreur}</Alert>
        </div>
      ) : null}

      {status === 'loading' ? (
        <div className="text-center" style={{ padding: 'var(--space-16)' }}>
          <Spinner large />
        </div>
      ) : status === 'error' ? (
        <Alert variant="warning">La liste n'a pas pu être chargée. Réessayez plus tard.</Alert>
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Membre</th>
                <th>Rôle</th>
                <th>Poste</th>
                <th>Charge</th>
                <th>Statut</th>
                <th>Dernière connexion</th>
                <th aria-hidden="true" />
              </tr>
            </thead>
            <tbody>
              {items.map((m) => {
                const roleInfo = ROLE_LABELS[m.role] ?? { label: m.role, badge: 'badge-neutral' }
                const soi = m.id === user?.id
                const enCours = busyId === m.id
                return (
                  <tr key={m.id}>
                    <td>
                      <div className="fw-medium body-sm">{m.prenom} {m.nom}</div>
                      <div className="caption">{m.email}</div>
                    </td>
                    <td><span className={`badge ${roleInfo.badge}`}>{roleInfo.label}</span></td>
                    <td className="body-sm">{m.poste}</td>
                    <td className="caption">
                      {m.dossiers_evalues} / {m.dossiers_affectes} évalué{m.dossiers_affectes > 1 ? 's' : ''}
                    </td>
                    <td>
                      <span className={`badge ${m.actif ? 'badge-success' : 'badge-neutral'}`}>
                        {m.actif ? 'Actif' : 'Désactivé'}
                      </span>
                    </td>
                    <td className="caption">
                      {m.derniere_connexion_le ? formatDateFr(m.derniere_connexion_le) : 'Jamais'}
                    </td>
                    <td>
                      <div className="flex gap-2">
                        {m.actif ? (
                          <button
                            type="button"
                            className="btn btn-sm btn-danger"
                            disabled={enCours || soi}
                            title={soi ? 'Vous ne pouvez pas désactiver votre propre compte.' : undefined}
                            onClick={() => setConfirmActivation({ membre: m, actif: false })}
                          >
                            Désactiver
                          </button>
                        ) : (
                          <button
                            type="button"
                            className="btn btn-sm btn-outline"
                            disabled={enCours}
                            onClick={() => setConfirmActivation({ membre: m, actif: true })}
                          >
                            Réactiver
                          </button>
                        )}
                        <button
                          type="button"
                          className="btn btn-sm btn-outline"
                          disabled={enCours}
                          onClick={() => setConfirmReset(m)}
                        >
                          Réinitialiser le mot de passe
                        </button>
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {showForm ? (
        <MembreFormModal
          saving={creating}
          onCancel={() => setShowForm(false)}
          onSubmit={soumettreCreation}
        />
      ) : null}

      {confirmActivation ? (
        <ConfirmDialog
          title={
            confirmActivation.actif
              ? `Réactiver ${confirmActivation.membre.prenom} ${confirmActivation.membre.nom} ?`
              : `Désactiver ${confirmActivation.membre.prenom} ${confirmActivation.membre.nom} ?`
          }
          message={
            confirmActivation.actif
              ? 'Le compte pourra de nouveau se connecter.'
              : "L'accès est coupé immédiatement — la session en cours est fermée au prochain chargement."
          }
          confirmLabel={confirmActivation.actif ? 'Réactiver' : 'Désactiver'}
          danger={!confirmActivation.actif}
          loading={busyId === confirmActivation.membre.id}
          onCancel={() => setConfirmActivation(null)}
          onConfirm={executerActivation}
        />
      ) : null}

      {confirmReset ? (
        <ConfirmDialog
          title={`Réinitialiser le mot de passe de ${confirmReset.prenom} ${confirmReset.nom} ?`}
          message="Un nouveau mot de passe provisoire sera généré et affiché une seule fois. L'ancien cessera de fonctionner."
          confirmLabel="Réinitialiser"
          loading={busyId === confirmReset.id}
          onCancel={() => setConfirmReset(null)}
          onConfirm={executerReset}
        />
      ) : null}

      {motDePasse ? (
        <MotDePasseDialog
          titre={motDePasse.titre}
          email={motDePasse.email}
          motDePasse={motDePasse.valeur}
          onClose={() => setMotDePasse(null)}
        />
      ) : null}
    </AppShell>
  )
}
