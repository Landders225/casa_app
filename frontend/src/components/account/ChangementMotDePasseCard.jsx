import { useState } from 'react'
import { Alert } from '../ui/Alert.jsx'
import { FormField } from '../ui/FormField.jsx'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

const VIDE = { current_password: '', password: '', password_confirmation: '' }

/**
 * Changement de mot de passe (self-service) — partagé entre l'espace candidat
 * (Lot 13, `PUT /candidat/mot-de-passe`, ADR-32) et l'espace équipe (Lot 15a,
 * `PUT /equipe/mot-de-passe`) : le composant n'a AUCUN contenu spécifique à une
 * audience (labels neutres), seul le chemin d'API varie — d'où le partage
 * plutôt que la duplication (Étape 1, Q1), à l'inverse du choix fait côté
 * backend (deux contrôleurs distincts, comme `Auth\`/`Candidat\MotDePasseController`).
 *
 * Le mot de passe ACTUEL est toujours demandé (aucune UI ne peut le contourner
 * — le serveur le vérifie de toute façon). Après succès, TOUTES les autres
 * sessions sont coupées côté serveur (pas celle-ci) : on l'indique dans le
 * message, sans rien faire de spécial côté client.
 */
export function ChangementMotDePasseCard({ endpoint }) {
  const [form, setForm] = useState(VIDE)
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)
  const [succes, setSucces] = useState(false)
  const [saving, setSaving] = useState(false)

  const set = (champ) => (e) => setForm((f) => ({ ...f, [champ]: e.target.value }))

  const submit = async (e) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    setMessage(null)
    setSucces(false)
    try {
      await apiClient.put(endpoint, form)
      setSucces(true)
      setForm(VIDE)
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        setErrors(err.errors ?? {})
        setMessage(err.errors && Object.keys(err.errors).length ? null : err.message)
      } else {
        setMessage(err instanceof ApiError ? err.message : 'La mise à jour a échoué.')
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="card">
      <div className="card-header">
        <h3>Sécurité</h3>
      </div>

      {succes ? (
        <div style={{ marginBottom: 'var(--space-4)' }}>
          <Alert variant="success">
            Votre mot de passe a été modifié. Vos autres sessions ont été déconnectées.
          </Alert>
        </div>
      ) : null}
      {message ? (
        <div style={{ marginBottom: 'var(--space-4)' }}>
          <Alert variant="danger">{message}</Alert>
        </div>
      ) : null}

      <form onSubmit={submit} noValidate>
        <FormField
          label="Mot de passe actuel"
          type="password"
          error={errors.current_password}
          inputProps={{ value: form.current_password, onChange: set('current_password'), autoComplete: 'current-password', required: true }}
        />
        <FormField
          label="Nouveau mot de passe"
          type="password"
          error={errors.password}
          hint="Au moins 10 caractères, avec majuscule, minuscule et chiffre."
          inputProps={{ value: form.password, onChange: set('password'), autoComplete: 'new-password', required: true }}
        />
        <FormField
          label="Confirmer le nouveau mot de passe"
          type="password"
          inputProps={{ value: form.password_confirmation, onChange: set('password_confirmation'), autoComplete: 'new-password', required: true }}
        />
        <button type="submit" className={`btn btn-outline btn-block${saving ? ' is-loading' : ''}`} disabled={saving}>
          {saving ? 'Modification…' : 'Changer mon mot de passe'}
        </button>
      </form>

      <p className="caption" style={{ marginTop: 'var(--space-3)' }}>
        <i className="fa-solid fa-circle-info" aria-hidden="true" /> Vous resterez connecté(e) ici ; vos
        autres appareils/navigateurs devront se reconnecter.
      </p>
    </div>
  )
}
