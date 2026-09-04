/**
 * Boîte de confirmation minimale (validation définitive dossier/entretien,
 * Lot 8c-2) — réutilise `.modal-overlay`/`.modal` du design system (déjà
 * employé par `SuccessModal` côté candidat, Lot 8b-2).
 */
export function ConfirmDialog({ title, message, confirmLabel = 'Confirmer', onConfirm, onCancel, loading }) {
  return (
    <div className="modal-overlay" role="dialog" aria-modal="true" aria-label={title}>
      <div className="modal">
        <div className="modal-body">
          <h3>{title}</h3>
          <p className="body-sm" style={{ marginTop: 'var(--space-3)' }}>{message}</p>
        </div>
        <div className="modal-footer">
          <button type="button" className="btn btn-outline" onClick={onCancel} disabled={loading}>
            Annuler
          </button>
          <button type="button" className="btn btn-primary" onClick={onConfirm} disabled={loading}>
            {loading ? 'Validation…' : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  )
}
