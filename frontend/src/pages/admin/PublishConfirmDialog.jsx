import { useState } from 'react'
import { Alert } from '../../components/ui/Alert.jsx'

/**
 * Confirmation de PUBLICATION (Lot 8d-2, Étape 1 Q4) — l'acte le plus
 * irréversible de l'application (des dizaines/centaines de candidats basculent
 * de « en cours de traitement » à leur décision définitive, sans dé-publication
 * possible). Un `ConfirmDialog` Annuler/Publier standard le mettrait au même
 * niveau qu'un toggle de filière — disproportionné.
 *
 * Friction délibérée (pattern GitHub/Stripe pour les actes destructeurs) :
 * le bouton « Publier définitivement » reste désactivé tant que l'admin n'a
 * pas TAPÉ le nom EXACT de la campagne. Ça empêche le clic réflexe et force à
 * lire QUELLE campagne on s'apprête à publier — l'irréversibilité doit être
 * tangible au moment du clic, pas juste écrite dans un paragraphe qu'on saute.
 */
export function PublishConfirmDialog({ campagneNom, loading, onConfirm, onCancel }) {
  const [saisie, setSaisie] = useState('')
  const pretAPublier = saisie === campagneNom

  return (
    <div className="modal-overlay" role="dialog" aria-modal="true" aria-label="Publier les résultats">
      <div className="modal">
        <div className="modal-header">
          <h3>Publier les résultats de « {campagneNom} » ?</h3>
        </div>
        <div className="modal-body">
          <Alert variant="danger" title="Action définitive et irréversible">
            Cette action rend la décision (retenu / liste d'attente / non retenu) visible par TOUS les candidats de
            cette campagne, dans leur espace. Il n'existe aucune dé-publication : une fois publiée, cette campagne le
            reste.
          </Alert>
          <p className="body-sm" style={{ margin: 'var(--space-4) 0 var(--space-2)' }}>
            Pour confirmer, tapez le nom exact de la campagne :
          </p>
          <p className="fw-semibold body-sm" style={{ marginBottom: 'var(--space-2)' }}>{campagneNom}</p>
          <input
            className="input"
            aria-label="Nom de la campagne (confirmation)"
            value={saisie}
            onChange={(e) => setSaisie(e.target.value)}
            placeholder="Tapez le nom de la campagne ici"
            autoComplete="off"
            disabled={loading}
          />
        </div>
        <div className="modal-footer">
          <button type="button" className="btn btn-outline" onClick={onCancel} disabled={loading}>
            Annuler
          </button>
          <button type="button" className="btn btn-danger" onClick={onConfirm} disabled={!pretAPublier || loading}>
            {loading ? 'Publication…' : 'Publier définitivement'}
          </button>
        </div>
      </div>
    </div>
  )
}
