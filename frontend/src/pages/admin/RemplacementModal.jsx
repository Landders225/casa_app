import { useState } from 'react'
import { Alert } from '../../components/ui/Alert.jsx'

/**
 * Remplacement d'un candidat retenu indisponible (Lot 8d-3).
 *
 * `promuPreview` est une LECTURE de ce que le serveur a déjà décidé et trié
 * (le premier `liste_attente` de la même filière dans `filiereActive.lignes`,
 * déjà trié par `rang` par `ClassementResource`) — PAS un recalcul de
 * classement : `RemplacementController` sélectionne exactement de la même
 * façon (même filière, `orderBy('rang')`). C'est un APERÇU, présenté comme
 * tel (« sous réserve ») ; le résultat définitif vient de la réponse
 * `POST /remplacements`, affiché ensuite par `Classement.jsx`.
 */
export function RemplacementModal({ ligne, promuPreview, saving, error, onCancel, onConfirm }) {
  const [motif, setMotif] = useState('')
  const pret = motif.trim().length >= 3

  return (
    <div className="modal-overlay" role="dialog" aria-modal="true" aria-label="Déclarer indisponible">
      <div className="modal">
        <div className="modal-header">
          <h3>Déclarer {ligne.candidat.prenom} {ligne.candidat.nom} indisponible ?</h3>
        </div>
        <div className="modal-body">
          {error ? (
            <div style={{ marginBottom: 'var(--space-4)' }}>
              <Alert variant="danger">{error}</Alert>
            </div>
          ) : null}
          <Alert variant="danger" title="Action irréversible">
            {ligne.candidat.prenom} {ligne.candidat.nom} ({ligne.numero_dossier}) passera de « Retenu » à «
            Indisponible ».
          </Alert>
          <div className="card card-sunken" style={{ padding: 'var(--space-4)', marginTop: 'var(--space-4)' }}>
            {promuPreview ? (
              <p className="body-sm">
                Sera promu « Retenu » (sous réserve) : <strong>{promuPreview.candidat.prenom} {promuPreview.candidat.nom}</strong>{' '}
                ({promuPreview.numero_dossier}), actuellement rang {promuPreview.rang} en liste d'attente.
              </p>
            ) : (
              <p className="body-sm">Aucun candidat en liste d'attente pour cette filière : personne ne sera promu.</p>
            )}
          </div>
          <div className="form-group" style={{ marginTop: 'var(--space-4)', marginBottom: 0 }}>
            <label className="label" htmlFor="motif-remplacement">Motif (obligatoire)</label>
            <textarea
              id="motif-remplacement"
              className="textarea"
              rows={3}
              value={motif}
              onChange={(e) => setMotif(e.target.value)}
              placeholder="Désistement, empêchement..."
              maxLength={2000}
            />
          </div>
        </div>
        <div className="modal-footer">
          <button type="button" className="btn btn-outline" onClick={onCancel} disabled={saving}>
            Annuler
          </button>
          <button type="button" className="btn btn-danger" disabled={!pret || saving} onClick={() => onConfirm(motif.trim())}>
            {saving ? 'Remplacement…' : 'Confirmer le remplacement'}
          </button>
        </div>
      </div>
    </div>
  )
}
