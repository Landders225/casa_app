import { useEffect, useState } from 'react'
import { Alert } from '../../components/ui/Alert.jsx'
import { Checkbox } from '../../components/ui/Checkbox.jsx'
import { FormField } from '../../components/ui/FormField.jsx'
import { Spinner } from '../../components/ui/Spinner.jsx'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'

/**
 * Création d'une campagne (Lot 17) — `POST /api/admin/campagnes`.
 *
 * Aucun champ « statut » : une campagne créée par l'écran est TOUJOURS
 * `brouillon` (serveur, Étape 1 Q1) — le passage à `ouverte` se fait ensuite
 * depuis l'écran Campagnes (transition existante). Le choix des filières
 * réutilise `GET /api/filieres` (public, déjà consommé ailleurs) filtré aux
 * filières ACTIVES — une filière inactive n'accepterait de toute façon aucune
 * candidature pour cette cohorte.
 */
export function CampagneFormModal({ saving, onCancel, onSubmit }) {
  const [filieres, setFilieres] = useState({ status: 'loading', items: [] })
  const [nom, setNom] = useState('')
  const [dateOuverture, setDateOuverture] = useState('')
  const [dateCloture, setDateCloture] = useState('')
  const [quotas, setQuotas] = useState({}) // { [filiereId]: { cochee: bool, quota: string } }
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)

  useEffect(() => {
    apiClient
      .get('/filieres')
      .then((res) => {
        const actives = (res.data ?? []).filter((f) => f.actif)
        setFilieres({ status: 'ready', items: actives })
        setQuotas(Object.fromEntries(actives.map((f) => [f.id, { cochee: false, quota: '24' }])))
      })
      .catch(() => setFilieres({ status: 'error', items: [] }))
  }, [])

  const toggleFiliere = (id) => {
    setQuotas((q) => ({ ...q, [id]: { ...q[id], cochee: !q[id].cochee } }))
  }

  const changerQuota = (id) => (e) => {
    setQuotas((q) => ({ ...q, [id]: { ...q[id], quota: e.target.value } }))
  }

  const submit = async (e) => {
    e.preventDefault()
    setErrors({})
    setMessage(null)

    const choisies = Object.entries(quotas).filter(([, v]) => v.cochee)
    if (choisies.length === 0) {
      setMessage('Choisissez au moins une filière pour cette campagne.')
      return
    }

    const payload = {
      nom,
      date_ouverture: dateOuverture,
      date_cloture: dateCloture,
      filieres: choisies.map(([filiere_id, v]) => ({ filiere_id, quota: Number(v.quota) || 0 })),
    }

    try {
      await onSubmit(payload)
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
    <div className="modal-overlay" role="dialog" aria-modal="true" aria-label="Créer une campagne">
      <form className="modal" onSubmit={submit}>
        <div className="modal-header">
          <h3>Créer une campagne</h3>
        </div>
        <div className="modal-body">
          {message ? (
            <div style={{ marginBottom: 'var(--space-3)' }}>
              <Alert variant="danger">{message}</Alert>
            </div>
          ) : null}

          <FormField
            label="Nom de la campagne"
            error={errors.nom}
            inputProps={{ value: nom, onChange: (e) => setNom(e.target.value), placeholder: 'Cohorte 2 — 2027', required: true }}
          />

          <div className="form-row">
            <FormField
              label="Date d'ouverture prévue"
              type="date"
              error={errors.date_ouverture}
              inputProps={{ value: dateOuverture, onChange: (e) => setDateOuverture(e.target.value), required: true }}
            />
            <FormField
              label="Date de clôture prévue"
              type="date"
              error={errors.date_cloture}
              inputProps={{ value: dateCloture, onChange: (e) => setDateCloture(e.target.value), required: true }}
            />
          </div>

          <p className="label" style={{ marginTop: 'var(--space-4)' }}>Filières & quotas initiaux</p>
          {errors.filieres ? (
            <p className="input-error">
              <i className="fa-solid fa-circle-exclamation" aria-hidden="true" /> {Array.isArray(errors.filieres) ? errors.filieres[0] : errors.filieres}
            </p>
          ) : null}

          {filieres.status === 'loading' ? (
            <Spinner />
          ) : filieres.status === 'error' ? (
            <Alert variant="warning">La liste des filières n'a pas pu être chargée.</Alert>
          ) : (
            <div className="grid" style={{ gap: 'var(--space-3)' }}>
              {filieres.items.map((f) => (
                <div key={f.id} className="flex items-center gap-3">
                  <Checkbox label={f.nom} checked={quotas[f.id]?.cochee ?? false} onChange={() => toggleFiliere(f.id)} />
                  <input
                    type="number"
                    className="input"
                    style={{ width: '5rem' }}
                    min="0"
                    disabled={!quotas[f.id]?.cochee}
                    value={quotas[f.id]?.quota ?? ''}
                    onChange={changerQuota(f.id)}
                    aria-label={`Quota pour ${f.nom}`}
                  />
                </div>
              ))}
            </div>
          )}
        </div>
        <div className="modal-footer">
          <button type="button" className="btn btn-outline" onClick={onCancel} disabled={saving}>
            Annuler
          </button>
          <button type="submit" className="btn btn-primary" disabled={saving || filieres.status !== 'ready'}>
            {saving ? 'Création…' : 'Créer la campagne (brouillon)'}
          </button>
        </div>
      </form>
    </div>
  )
}
