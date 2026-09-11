import { AppShell } from '../../components/layout/AppShell.jsx'
import { roleLabel } from '../../components/layout/navConfig.js'
import { ChangementMotDePasseCard } from '../../components/account/ChangementMotDePasseCard.jsx'
import { useAuth } from '../../auth/useAuth.js'

/**
 * « Mon compte » (Lot 15a) — partagé évaluateur + administrateur (route
 * `/equipe/mon-compte`, `role:evaluateur,administrateur`). Reprend l'esprit de
 * la maquette évaluateur (`pages/evaluator/profil.html`, jamais portée) :
 * identité en LECTURE SEULE (prénom/nom/poste/e-mail/rôle — un enregistrement
 * RH que seul l'admin corrige depuis l'écran Équipe, jamais le membre
 * lui-même) + carte « Sécurité » (mot de passe, self-service).
 *
 * Aucun appel réseau dédié : `useAuth()` expose déjà tout via `GET /api/me`
 * (Étape 1, Q2 — coût quasi nul).
 */
export function MonCompte() {
  const { user, role } = useAuth()
  const profil = user?.profil

  return (
    <AppShell title="Mon compte">
      <div className="page-head">
        <div>
          <h2>Mon compte</h2>
          <p className="text-muted">Vos informations et la sécurité de votre compte équipe projet.</p>
        </div>
      </div>

      <div className="dashboard-layout">
        <div className="card">
          <div className="card-header">
            <h3>Identité</h3>
            <span className="badge badge-neutral">
              <i className="fa-solid fa-lock" aria-hidden="true" /> Non modifiable
            </span>
          </div>
          <div className="form-row">
            <div className="form-group">
              <label className="label" htmlFor="compte-prenom">Prénom</label>
              <input id="compte-prenom" className="input" value={profil?.prenom ?? ''} readOnly disabled />
            </div>
            <div className="form-group">
              <label className="label" htmlFor="compte-nom">Nom</label>
              <input id="compte-nom" className="input" value={profil?.nom ?? ''} readOnly disabled />
            </div>
          </div>
          <div className="form-group">
            <label className="label" htmlFor="compte-poste">Poste</label>
            <input id="compte-poste" className="input" value={profil?.poste ?? ''} readOnly disabled />
          </div>
          <div className="form-group">
            <label className="label" htmlFor="compte-email">E-mail</label>
            <input id="compte-email" className="input" value={user?.email ?? ''} readOnly disabled />
          </div>
          <div className="form-group" style={{ marginBottom: 0 }}>
            <label className="label" htmlFor="compte-role">Rôle</label>
            <input id="compte-role" className="input" value={roleLabel[role] ?? role ?? ''} readOnly disabled />
          </div>
          <p className="caption" style={{ marginTop: 'var(--space-3)' }}>
            Pour corriger votre identité (prénom, nom, poste), contactez un administrateur.
          </p>
        </div>

        <ChangementMotDePasseCard endpoint="/equipe/mot-de-passe" />
      </div>
    </AppShell>
  )
}
