import { useState } from 'react'
import { Alert } from '../../components/ui/Alert.jsx'
import { FormField } from '../../components/ui/FormField.jsx'
import { ApiError } from '../../lib/ApiError.js'

/**
 * Édition nom/dates d'une campagne (Lot 17, Étape 1 Q3) —
 * `PUT /api/admin/campagnes/{id}`.
 *
 * Découpage cosmétique (nom, toujours éditable) vs effet réel sur le
 * processus (dates, verrouillées si `cloturee`) : purement AFFICHÉ ici (champs
 * dates désactivés + note), le vrai verrou reste 100 % serveur (409 verbatim
 * si contourné).
 */
export function ModifierCampagneModal({ campagne, saving, onCancel, onSubmit }) {
  const [nom, setNom] = useState(campagne.nom)
  const [dateOuverture, setDateOuverture] = useState(campagne.date_ouverture)
  const [dateCloture, setDateCloture] = useState(campagne.date_cloture)
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)

  const datesVerrouillees = campagne.statut === 'cloturee'

  const submit = async (e) => {
    e.preventDefault()
    setErrors({})
    setMessage(null)
    try {
      await onSubmit({ nom, date_ouverture: dateOuverture, date_cloture: dateCloture })
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        setErrors(err.errors ?? {})
        setMessage(err.message)
      } else {
        setMessage(err instanceof ApiError ? err.message : 'La modification a échoué.')
      }
    }
  }

  return (
    <div className="modal-overlay" role="dialog" aria-modal="true" aria-label={`Modifier « ${campagne.nom} »`}>
      <form className="modal" onSubmit={submit}>
        <div className="modal-header">
          <h3>Modifier « {campagne.nom} »</h3>
        </div>
        <div className="modal-body">
          {message ? (
            <div style={{ marginBottom: 'var(--space-3)' }}>
              <Alert variant="danger">{message}</Alert>
            </div>
          ) : null}
          {datesVerrouillees ? (
            <div style={{ marginBottom: 'var(--space-3)' }}>
              <Alert variant="warning">
                Campagne clôturée : les dates sont verrouillées (effet réel sur le déroulé de la cohorte). Le nom reste éditable.
              </Alert>
            </div>
          ) : null}

          <FormField
            label="Nom de la campagne"
            error={errors.nom}
            inputProps={{ value: nom, onChange: (e) => setNom(e.target.value), required: true }}
          />

          <div className="form-row">
            <FormField
              label="Date d'ouverture prévue"
              type="date"
              error={errors.date_ouverture}
              inputProps={{ value: dateOuverture, onChange: (e) => setDateOuverture(e.target.value), required: true, disabled: datesVerrouillees }}
            />
            <FormField
              label="Date de clôture prévue"
              type="date"
              error={errors.date_cloture}
              inputProps={{ value: dateCloture, onChange: (e) => setDateCloture(e.target.value), required: true, disabled: datesVerrouillees }}
            />
          </div>
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
