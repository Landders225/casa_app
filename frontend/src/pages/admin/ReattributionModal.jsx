import { useState } from 'react'
import { Alert } from '../../components/ui/Alert.jsx'

const POOL_NON_AFFECTE = '__pool_non_affecte__'

/**
 * Réattribution d'un ou plusieurs dossiers DÉJÀ AFFECTÉS (Lot 20b) —
 * `POST /admin/reattributions`. Distincte d'`AssignModal` (première
 * affectation) : propose aussi « Remettre dans le lot non affecté »
 * (refusé par le serveur si un dossier est déjà verrouillé — message
 * affiché tel quel, pas de pré-filtrage client).
 *
 * CONFIRMATION EXPLICITE (pas une case cochée en silence) : la case
 * « J'ai compris » doit être cochée à chaque ouverture pour activer le
 * bouton — elle avertit d'un effet qui n'est PAS toujours réel (un brouillon
 * n'existe pas forcément), mais comme le client ne peut pas le savoir à
 * l'avance (aucun endpoint ne l'expose), l'avertissement reste systématique,
 * jamais calculé côté client.
 *
 * ATOMIQUE côté serveur : si un seul dossier n'est pas réattribuable, RIEN
 * n'est écrit et le message (422/409) est affiché TEL QUEL — ce modal ne le
 * reformule jamais, et NE FERME PAS sur échec.
 */
export function ReattributionModal({ count, evaluateurs, reattributing, error, onCancel, onConfirm }) {
  const [cible, setCible] = useState(evaluateurs[0]?.id ?? POOL_NON_AFFECTE)
  const [comprisParticiper, setComprisParticiper] = useState(false)

  const evaluateurId = cible === POOL_NON_AFFECTE ? null : cible

  return (
    <div className="modal-overlay" role="dialog" aria-modal="true" aria-label="Réattribuer">
      <div className="modal">
        <div className="modal-header">
          <h3>Réattribuer</h3>
        </div>
        <div className="modal-body">
          <p className="body-sm text-muted" style={{ marginBottom: 'var(--space-3)' }}>
            {count} dossier{count > 1 ? 's' : ''} sélectionné{count > 1 ? 's' : ''}, déjà affecté{count > 1 ? 's' : ''}.
          </p>

          {error ? (
            <div style={{ marginBottom: 'var(--space-3)' }}>
              <Alert variant="danger">{error}</Alert>
            </div>
          ) : null}

          <select
            className="select"
            aria-label="Nouvelle affectation"
            value={cible}
            onChange={(e) => setCible(e.target.value)}
            disabled={reattributing}
            style={{ marginBottom: 'var(--space-3)' }}
          >
            <option value={POOL_NON_AFFECTE}>Remettre dans le lot non affecté</option>
            {evaluateurs.map((e) => (
              <option key={e.id} value={e.id}>{e.prenom} {e.nom}</option>
            ))}
          </select>

          <Alert variant="warning">
            Si un dossier sélectionné a une évaluation commencée (note de dossier et/ou commentaire non validés,
            ou entretien planifié/réalisé non validé), elle sera réinitialisée — l'ancien évaluateur recommencerait
            à zéro. Un dossier dont la notation du dossier est déjà verrouillée ne peut pas revenir dans le lot non
            affecté : seule une réattribution vers un autre évaluateur reste possible pour lui.
          </Alert>

          <label className="flex items-center gap-2 caption" style={{ marginTop: 'var(--space-3)' }}>
            <input
              type="checkbox"
              checked={comprisParticiper}
              onChange={(e) => setComprisParticiper(e.target.checked)}
              disabled={reattributing}
            />
            J'ai compris : toute évaluation commencée et non validée sur ces dossiers sera réinitialisée.
          </label>
        </div>
        <div className="modal-footer">
          <button type="button" className="btn btn-outline" onClick={onCancel} disabled={reattributing}>
            Annuler
          </button>
          <button
            type="button"
            className="btn btn-primary"
            disabled={reattributing || !comprisParticiper}
            onClick={() => onConfirm(evaluateurId)}
          >
            {reattributing ? 'Réattribution…' : 'Réattribuer'}
          </button>
        </div>
      </div>
    </div>
  )
}
