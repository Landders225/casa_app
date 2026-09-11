import { useState } from 'react'
import { Alert } from '../../components/ui/Alert.jsx'
import { FormField } from '../../components/ui/FormField.jsx'
import { ApiError } from '../../lib/ApiError.js'

/**
 * Édition d'identité d'un membre d'équipe (Lot 15a) — `PATCH /api/admin/membres/{id}`
 * `{ prenom, nom, poste }`. Réservé à l'admin (écran Équipe), jamais le membre
 * lui-même — c'est un enregistrement RH que l'admin corrige, pas l'identité
 * personnelle du membre (cf. « Mon compte », qui ne l'expose qu'en lecture
 * seule). Pré-rempli avec les valeurs actuelles ; le rôle et l'e-mail
 * n'apparaissent même pas dans ce formulaire (aucune ambiguïté possible sur
 * ce qui est éditable ici).
 */
export function ModifierIdentiteModal({ membre, saving, onCancel, onSubmit }) {
  const [form, setForm] = useState({ prenom: membre.prenom ?? '', nom: membre.nom ?? '', poste: membre.poste ?? '' })
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
        setMessage(err.errors && Object.keys(err.errors).length ? null : err.message)
      } else {
        setMessage(err instanceof ApiError ? err.message : "La modification a échoué.")
      }
    }
  }

  return (
    <div className="modal-overlay" role="dialog" aria-modal="true" aria-label={`Modifier ${membre.prenom} ${membre.nom}`}>
      <form className="modal" onSubmit={submit}>
        <div className="modal-header">
          <h3>Modifier l'identité de {membre.prenom} {membre.nom}</h3>
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
            label="Poste"
            error={errors.poste}
            inputProps={{ value: form.poste, onChange: set('poste'), placeholder: 'Jury filière cuisine', required: true }}
          />

          <p className="input-hint" style={{ marginTop: 'var(--space-2)' }}>
            Le rôle et l'adresse e-mail ne se modifient pas depuis cet écran.
          </p>
        </div>
        <div className="modal-footer">
          <button type="button" className="btn btn-outline" onClick={onCancel} disabled={saving}>
            Annuler
          </button>
          <button type="submit" className="btn btn-primary" disabled={saving}>
            {saving ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </div>
      </form>
    </div>
  )
}
