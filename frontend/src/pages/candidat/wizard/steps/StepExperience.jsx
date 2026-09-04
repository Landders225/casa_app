import { useId, useState } from 'react'
import { FieldError } from '../../../../components/form/FieldError.jsx'
import { FileDropRow } from '../../../../components/form/FileDropRow.jsx'
import { ApiError } from '../../../../lib/ApiError.js'
import { EXPERIENCE_DOMAINES, EXPERIENCE_DUREES } from '../formStructure.js'

function ExperienceCard({ index, exp, form }) {
  const uid = useId()
  const [busy, setBusy] = useState(false)
  const [uploadError, setUploadError] = useState(null)
  const isTemp = String(exp.id).startsWith('tmp-')
  const ready = !isTemp

  const wrap = (fn) => async (...args) => {
    setBusy(true)
    setUploadError(null)
    try {
      await fn(...args)
    } catch (err) {
      setUploadError(err instanceof ApiError ? (err.errors?.fichier?.[0] || err.message) : 'Échec de l’envoi.')
    } finally {
      setBusy(false)
    }
  }

  // La saisie domaine/durée déclenche un appel réseau (création ou mise à jour de
  // la ligne) : on capture l'échec pour ne pas laisser l'utilisateur bloqué.
  const setField = (field) => wrap((value) => form.setExperienceField(exp.id, field, value))

  return (
    <div className="card experience-card" style={{ marginBottom: 'var(--space-4)' }}>
      <div className="flex justify-between items-center" style={{ marginBottom: 'var(--space-4)' }}>
        <h4>Expérience {index + 1}</h4>
        <button
          type="button"
          className="btn btn-icon btn-ghost"
          aria-label={`Retirer l'expérience ${index + 1}`}
          onClick={wrap(() => form.removeExperience(exp.id))}
        >
          <i className="fa-solid fa-trash" aria-hidden="true" />
        </button>
      </div>

      <div className="form-row">
        <div className="form-group">
          <label className="label" htmlFor={`${uid}-domaine`}>Domaine</label>
          <select
            id={`${uid}-domaine`}
            className="select"
            value={exp.domaine}
            onChange={(e) => setField('domaine')(e.target.value)}
          >
            <option value="">Sélectionner</option>
            {EXPERIENCE_DOMAINES.map((d) => (
              <option key={d.value} value={d.value}>{d.label}</option>
            ))}
          </select>
        </div>
        <div className="form-group">
          <label className="label" htmlFor={`${uid}-duree`}>Durée</label>
          <select
            id={`${uid}-duree`}
            className="select"
            value={exp.duree_categorie}
            onChange={(e) => setField('duree_categorie')(e.target.value)}
          >
            <option value="">Sélectionner</option>
            {EXPERIENCE_DUREES.map((d) => (
              <option key={d.value} value={d.value}>{d.label}</option>
            ))}
          </select>
        </div>
      </div>

      {ready ? (
        <FileDropRow
          label="Justificatif (attestation, certificat de travail…)"
          hint="Obligatoire"
          file={exp.justificatif}
          busy={busy}
          error={uploadError}
          onPick={wrap((f) => form.uploadExperienceJustif(exp.id, f))}
          onRemove={wrap(() => form.removeExperienceJustif(exp.id))}
        />
      ) : (
        <p className="input-hint">Choisissez le domaine et la durée pour pouvoir déposer le justificatif.</p>
      )}
    </div>
  )
}

export function StepExperience({ form, errors }) {
  const { experiences, addExperience } = form
  const generalError = Object.entries(errors).find(([k]) => k.startsWith('experiences.'))?.[1]

  return (
    <>
      <h3 style={{ marginBottom: 'var(--space-2)' }}>Expérience professionnelle</h3>
      <p className="caption" style={{ marginBottom: 'var(--space-5)' }}>
        Ajoutez chaque expérience pertinente avec son justificatif. Laissez la liste vide si vous n'avez aucune
        expérience.
      </p>

      {experiences.length === 0 ? (
        <div className="card card-flat" style={{ padding: 'var(--space-6)', marginBottom: 'var(--space-5)', textAlign: 'center' }}>
          <p className="body-sm text-muted">Aucune expérience ajoutée pour le moment.</p>
        </div>
      ) : (
        experiences.map((exp, i) => <ExperienceCard key={exp.id} index={i} exp={exp} form={form} />)
      )}

      <FieldError>{generalError}</FieldError>

      <button type="button" className="btn btn-outline" onClick={addExperience}>
        <i className="fa-solid fa-plus" aria-hidden="true" /> Ajouter une expérience
      </button>
    </>
  )
}
