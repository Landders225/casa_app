import { useState } from 'react'
import { Alert } from '../../components/ui/Alert.jsx'

/**
 * Confirmation à motif obligatoire (Lot 8d-3) — utilisée pour l'élimination
 * manuelle depuis la fiche candidat. DUPLIQUÉE de `pages/admin/MotifConfirmDialog.jsx`
 * (même contenu) plutôt que partagée : `pages/evaluateur/**` ne peut pas
 * importer `pages/admin/**` (garde de non-pont, symétrique depuis le 8d-1).
 *
 * Motif borné à `min:3` (miroir des `Request` Lot 6b) — le bouton de
 * confirmation reste désactivé tant que ce plancher n'est pas atteint ; le
 * 422 backend reste le dernier rempart si la course arrive quand même.
 */
export function MotifConfirmDialog({ title, message, extra, confirmLabel = 'Confirmer', loading, error, onConfirm, onCancel }) {
  const [motif, setMotif] = useState('')
  const pret = motif.trim().length >= 3

  return (
    <div className="modal-overlay" role="dialog" aria-modal="true" aria-label={title}>
      <div className="modal">
        <div className="modal-header">
          <h3>{title}</h3>
        </div>
        <div className="modal-body">
          {error ? (
            <div style={{ marginBottom: 'var(--space-4)' }}>
              <Alert variant="danger">{error}</Alert>
            </div>
          ) : null}
          <Alert variant="warning">{message}</Alert>
          {extra}
          <div className="form-group" style={{ marginTop: 'var(--space-4)', marginBottom: 0 }}>
            <label className="label" htmlFor="motif-obligatoire">Motif (obligatoire)</label>
            <textarea
              id="motif-obligatoire"
              className="textarea"
              rows={3}
              value={motif}
              onChange={(e) => setMotif(e.target.value)}
              placeholder="Expliquez la raison de cet acte..."
              maxLength={2000}
            />
          </div>
        </div>
        <div className="modal-footer">
          <button type="button" className="btn btn-outline" onClick={onCancel} disabled={loading}>
            Annuler
          </button>
          <button type="button" className="btn btn-danger" disabled={!pret || loading} onClick={() => onConfirm(motif.trim())}>
            {loading ? 'Enregistrement…' : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  )
}
