import { useState } from 'react'
import { Alert } from '../../components/ui/Alert.jsx'
import './Notation.css'
import { PointPicker } from './PointPicker.jsx'

/**
 * Correction exceptionnelle — volet entretien (Lot 8d-3) —
 * `POST .../correction/entretien`. Parité complète avec l'écran de notation
 * normal (8c-2) : la surface backend correspond exactement aux 12 sous-notes
 * + observation déjà notées — pas de réduction de portée ici (contrairement
 * au dossier, cf. `CorrectionDossierModal.jsx`).
 */
export function CorrectionEntretienModal({ entretien, saving, error, onCancel, onSave }) {
  const [notes, setNotes] = useState(() =>
    Object.fromEntries((entretien.sous_notes || []).map((sn) => [sn.code, Number(sn.points_attribues) || 0])),
  )
  const [observation, setObservation] = useState(entretien.observation || '')
  const [motif, setMotif] = useState('')
  const pret = motif.trim().length >= 3

  const sousNotesParRubrique = {}
  for (const sn of entretien.sous_notes || []) {
    ;(sousNotesParRubrique[sn.rubrique_code] ??= []).push(sn)
  }

  const submit = () => {
    onSave({ motif: motif.trim(), notes, observation })
  }

  return (
    <div className="modal-overlay" role="dialog" aria-modal="true" aria-label="Correction exceptionnelle — entretien">
      <div className="modal" style={{ maxWidth: 640 }}>
        <div className="modal-header">
          <h3>Correction exceptionnelle — entretien</h3>
        </div>
        <div className="modal-body">
          {error ? (
            <div style={{ marginBottom: 'var(--space-4)' }}>
              <Alert variant="danger">{error}</Alert>
            </div>
          ) : null}
          <Alert variant="warning" title="Vous rouvrez un entretien validé">
            Un nouveau score sera calculé côté serveur et remplacera l'ancien snapshot ; l'ancien reste tracé dans le
            journal d'audit. Possible seulement si la campagne n'est pas encore publiée.
          </Alert>

          {(entretien.rubriques || []).map((r) => (
            <div key={r.code} style={{ marginTop: 'var(--space-5)' }}>
              <h4>{r.label}</h4>
              {(sousNotesParRubrique[r.code] || []).map((sn) => (
                <div className="sc-row" key={sn.code}>
                  <span className="body-sm">{sn.label}</span>
                  <PointPicker
                    label={sn.label}
                    max={sn.max}
                    value={notes[sn.code] ?? 0}
                    onChange={(v) => setNotes((n) => ({ ...n, [sn.code]: v }))}
                  />
                </div>
              ))}
            </div>
          ))}

          <div className="form-group" style={{ marginTop: 'var(--space-5)' }}>
            <label className="label">Observation</label>
            <textarea className="textarea" rows={3} value={observation} onChange={(e) => setObservation(e.target.value)} maxLength={5000} />
          </div>

          <div className="form-group" style={{ marginTop: 'var(--space-4)', marginBottom: 0 }}>
            <label className="label" htmlFor="motif-correction-entretien">Motif de la correction (obligatoire)</label>
            <textarea
              id="motif-correction-entretien"
              className="textarea"
              rows={3}
              value={motif}
              onChange={(e) => setMotif(e.target.value)}
              maxLength={2000}
            />
          </div>
        </div>
        <div className="modal-footer">
          <button type="button" className="btn btn-outline" onClick={onCancel} disabled={saving}>
            Annuler
          </button>
          <button type="button" className="btn btn-danger" disabled={!pret || saving} onClick={submit}>
            {saving ? 'Correction…' : 'Enregistrer la correction'}
          </button>
        </div>
      </div>
    </div>
  )
}
