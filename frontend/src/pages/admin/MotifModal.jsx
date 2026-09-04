import { useState } from 'react'
import { Alert } from '../../components/ui/Alert.jsx'

/**
 * Saisie des motifs de décision (Lot 8d-2) — `PUT .../decision/motifs`.
 *
 * Les deux champs sont visuellement IMPOSSIBLES à confondre (Étape 1, préoccupation
 * centrale du périmètre) :
 *  - Motif interne (🔴) : bandeau danger, « jamais visible du candidat » répété
 *    dans le libellé ET le placeholder ;
 *  - Motif communicable (🟡) : bandeau avertissement, « visible par le candidat
 *    après publication » — et seulement après, jamais avant (le calcul seul ne
 *    fuit rien, ADR-03).
 *
 * Affiché pour les décisions `non_retenu` ET `liste_attente` (Étape 1, Q5 — le
 * backend n'a jamais restreint `PUT .../decision/motifs` à une décision précise),
 * jamais pour `retenu`.
 */
export function MotifModal({ ligne, saving, error, onCancel, onSave }) {
  const [motifInterne, setMotifInterne] = useState(ligne.motif_interne || '')
  const [motifCommunicable, setMotifCommunicable] = useState(ligne.motif_communicable || '')

  const submit = async () => {
    try {
      await onSave({
        motif_interne: motifInterne.trim() === '' ? null : motifInterne,
        motif_communicable: motifCommunicable.trim() === '' ? null : motifCommunicable,
      })
    } catch {
      // `error` est déjà posé par le hook — le modal reste ouvert pour corriger.
    }
  }

  return (
    <div className="modal-overlay" role="dialog" aria-modal="true" aria-label="Motif de la décision">
      <div className="modal">
        <div className="modal-header">
          <h3>Motif — {ligne.candidat.prenom} {ligne.candidat.nom}</h3>
        </div>
        <div className="modal-body">
          {error ? (
            <div style={{ marginBottom: 'var(--space-4)' }}>
              <Alert variant="danger">{error}</Alert>
            </div>
          ) : null}

          <div className="form-group">
            <Alert variant="danger" title="🔴 Motif interne — confidentiel">
              Note d'équipe. Jamais visible du candidat, en aucune circonstance.
            </Alert>
            <textarea
              className="textarea"
              rows={3}
              style={{ marginTop: 'var(--space-3)' }}
              placeholder="Note interne, jamais visible du candidat..."
              value={motifInterne}
              onChange={(e) => setMotifInterne(e.target.value)}
              maxLength={2000}
            />
          </div>

          <div className="form-group" style={{ marginBottom: 0 }}>
            <Alert variant="warning" title="🟡 Motif communicable — visible par le candidat après publication">
              Affiché au candidat uniquement après la publication des résultats de cette campagne. Laissé vide, un message générique s'applique.
            </Alert>
            <textarea
              className="textarea"
              rows={3}
              style={{ marginTop: 'var(--space-3)' }}
              placeholder="Laisser vide pour le message générique par défaut..."
              value={motifCommunicable}
              onChange={(e) => setMotifCommunicable(e.target.value)}
              maxLength={2000}
            />
          </div>
        </div>
        <div className="modal-footer">
          <button type="button" className="btn btn-outline" onClick={onCancel} disabled={saving}>
            Annuler
          </button>
          <button type="button" className="btn btn-primary" onClick={submit} disabled={saving}>
            {saving ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </div>
      </div>
    </div>
  )
}
