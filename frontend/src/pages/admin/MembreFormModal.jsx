import { useState } from 'react'
import { Alert } from '../../components/ui/Alert.jsx'
import { FormField } from '../../components/ui/FormField.jsx'
import { ApiError } from '../../lib/ApiError.js'
import { ROLE_MEMBRE_LABELS } from './optionLabels.js'

/**
 * Création d'un membre d'équipe (Lot 11b) — `POST /api/admin/membres`.
 *
 * Le select de rôle ne propose QUE « Évaluateur » et « Administrateur »
 * (`ROLE_MEMBRE_LABELS`) : « Candidat » n'existe pas par cette voie, côté client
 * comme côté serveur (`EnregistrerMembreRequest` → 422). Aucun champ de mot de
 * passe : le serveur le génère et le renvoie une fois (`MotDePasseDialog`).
 *
 * Sur 422, les erreurs champ par champ sont affichées sous chaque champ ;
 * le modal ne se ferme pas, la saisie est conservée.
 */
export function MembreFormModal({ saving, onCancel, onSubmit }) {
  const [form, setForm] = useState({ prenom: '', nom: '', email: '', poste: '', role: 'evaluateur' })
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)

  const set = (champ) => (e) => setForm((f) => ({ ...f, [champ]: e.target.value }))

  const submit = async (e) => {
    e.preventDefault()
    setErrors({})
    setMessage(null)
    try {
      await onSubmit(form)
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        setErrors(err.errors ?? {})
        setMessage(err.message)
      } else {
        setMessage(err instanceof ApiError ? err.message : 'La création a échoué.')
      }
    }
  }

  return (
    <div className="modal-overlay" role="dialog" aria-modal="true" aria-label="Ajouter un membre">
      <form className="modal" onSubmit={submit}>
        <div className="modal-header">
          <h3>Ajouter un membre de l'équipe</h3>
        </div>
        <div className="modal-body">
          {message ? (
            <div style={{ marginBottom: 'var(--space-3)' }}>
              <Alert variant="danger">{message}</Alert>
            </div>
          ) : null}

          <div className="form-row">
            <FormField
              label="Prénom"
              error={errors.prenom}
              inputProps={{ value: form.prenom, onChange: set('prenom'), autoComplete: 'given-name', required: true }}
            />
            <FormField
              label="Nom"
              error={errors.nom}
              inputProps={{ value: form.nom, onChange: set('nom'), autoComplete: 'family-name', required: true }}
            />
          </div>

          <FormField
            label="Adresse e-mail (identifiant de connexion)"
            type="email"
            error={errors.email}
            inputProps={{ value: form.email, onChange: set('email'), autoComplete: 'email', required: true }}
          />

          <FormField
            label="Poste"
            error={errors.poste}
            inputProps={{ value: form.poste, onChange: set('poste'), placeholder: 'Jury filière cuisine', required: true }}
          />

          <div className="form-group">
            <label className="label" htmlFor="membre-role">Rôle</label>
            <select
              id="membre-role"
              className={`select${errors.role ? ' is-error' : ''}`}
              value={form.role}
              onChange={set('role')}
              aria-invalid={errors.role ? 'true' : undefined}
            >
              {Object.entries(ROLE_MEMBRE_LABELS).map(([valeur, libelle]) => (
                <option key={valeur} value={valeur}>{libelle}</option>
              ))}
            </select>
            {errors.role ? (
              <p className="input-error">
                <i className="fa-solid fa-circle-exclamation" aria-hidden="true" /> {errors.role[0]}
              </p>
            ) : null}
          </div>

          <p className="input-hint" style={{ marginTop: 'var(--space-2)' }}>
            Un mot de passe provisoire sera généré et affiché une seule fois.
          </p>
        </div>
        <div className="modal-footer">
          <button type="button" className="btn btn-outline" onClick={onCancel} disabled={saving}>
            Annuler
          </button>
          <button type="submit" className="btn btn-primary" disabled={saving}>
            {saving ? 'Création…' : 'Créer le compte'}
          </button>
        </div>
      </form>
    </div>
  )
}
