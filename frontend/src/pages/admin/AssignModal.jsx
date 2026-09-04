import { useState } from 'react'
import { Alert } from '../../components/ui/Alert.jsx'

/**
 * Modal d'affectation en masse (Lot 8d-1) — `POST /admin/affectations`.
 *
 * ATOMIQUE côté serveur (Lot 6a) : si une seule candidature n'est pas
 * affectable, RIEN n'est écrit et le backend renvoie 422 avec la liste des
 * refusées (numéros de dossier inclus). Ce modal affiche ce message TEL QUEL
 * et NE FERME PAS, NE VIDE PAS la sélection sur échec — l'utilisateur peut
 * décocher les dossiers fautifs et réessayer sans tout reprendre à zéro.
 */
export function AssignModal({ count, evaluateurs, assigning, error, onCancel, onConfirm }) {
  const [evaluateurId, setEvaluateurId] = useState(evaluateurs[0]?.id ?? '')

  return (
    <div className="modal-overlay" role="dialog" aria-modal="true" aria-label="Affecter à un évaluateur">
      <div className="modal">
        <div className="modal-header">
          <h3>Affecter à un évaluateur</h3>
        </div>
        <div className="modal-body">
          <p className="body-sm text-muted" style={{ marginBottom: 'var(--space-3)' }}>
            {count} dossier{count > 1 ? 's' : ''} sélectionné{count > 1 ? 's' : ''}.
          </p>
          {error ? (
            <div style={{ marginBottom: 'var(--space-3)' }}>
              <Alert variant="danger">{error}</Alert>
            </div>
          ) : null}
          <select
            className="select"
            aria-label="Évaluateur"
            value={evaluateurId}
            onChange={(e) => setEvaluateurId(e.target.value)}
            disabled={assigning || evaluateurs.length === 0}
          >
            {evaluateurs.length === 0 ? <option value="">Aucun évaluateur disponible</option> : null}
            {evaluateurs.map((e) => (
              <option key={e.id} value={e.id}>{e.prenom} {e.nom}</option>
            ))}
          </select>
        </div>
        <div className="modal-footer">
          <button type="button" className="btn btn-outline" onClick={onCancel} disabled={assigning}>
            Annuler
          </button>
          <button
            type="button"
            className="btn btn-primary"
            disabled={assigning || !evaluateurId}
            onClick={() => onConfirm(evaluateurId)}
          >
            {assigning ? 'Affectation…' : 'Affecter'}
          </button>
        </div>
      </div>
    </div>
  )
}
