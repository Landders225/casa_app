/**
 * Boîte de confirmation minimale (transitions de campagne, Lot 8d-1) —
 * réutilise `.modal-overlay`/`.modal` du design system. Délibérément DUPLIQUÉE
 * de `pages/evaluateur/ConfirmDialog.jsx` (même contenu, même rôle) plutôt que
 * partagée entre arbres : les trois espaces (candidat/évaluateur/admin)
 * restent étanches (cf. `noBridge.test.js`).
 */
export function ConfirmDialog({ title, message, confirmLabel = 'Confirmer', danger, loading, onConfirm, onCancel }) {
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
          <button type="button" className={`btn ${danger ? 'btn-danger' : 'btn-primary'}`} onClick={onConfirm} disabled={loading}>
            {loading ? 'Confirmation…' : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  )
}
