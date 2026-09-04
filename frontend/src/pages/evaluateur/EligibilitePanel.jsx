import { Alert } from '../../components/ui/Alert.jsx'
import { formatDateFr } from '../../lib/formatDate.js'
import { ELIGIBILITE_LABELS, ORIGINE_LABELS } from './optionLabels.js'

/**
 * Panneau Éligibilité — partagé entre la fiche candidat (8c-1) et les écrans
 * de notation dossier/entretien (8c-2). N'affiche QUE `statut_eligibilite_interne`
 * et `criteres_eliminatoires[].detail`, déjà calculés et rédigés côté serveur
 * (`ServiceEligibilite`) — jamais recalculé ni reformulé ici (ADR-06).
 *
 * Rangé dans `pages/evaluateur/` (pas remonté dans `components/`) : c'est un
 * composant évaluateur réutilisé entre écrans évaluateur, la garde de non-pont
 * (`noBridge.test.js`) continue de porter sur tout l'arbre.
 */
export function EligibilitePanel({ dossier }) {
  const info = ELIGIBILITE_LABELS[dossier.statut_eligibilite_interne] ?? {
    label: dossier.statut_eligibilite_interne,
    badge: 'badge-neutral',
  }
  const criteres = dossier.criteres_eliminatoires || []

  return (
    <div className="card">
      <h3 style={{ marginBottom: 'var(--space-4)' }}>
        <i className="fa-solid fa-shield-halved" style={{ color: 'var(--casa-primary-600)' }} aria-hidden="true" />{' '}
        Éligibilité
      </h3>
      <span className={`badge ${info.badge} badge-lg`} style={{ marginBottom: 'var(--space-4)', display: 'inline-block' }}>
        {info.label}
      </span>

      {dossier.statut_eligibilite_interne === 'non_eligible' ? (
        <Alert variant="danger" title="Critère(s) éliminatoire(s) déclenché(s)">
          <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)', marginTop: 'var(--space-2)' }}>
            {criteres.map((c) => (
              <div className="body-sm" key={`${c.code_critere}-${c.declenche_le}`}>
                <strong>{c.code_critere}</strong> — {c.detail}
                <div className="caption" style={{ marginTop: 2 }}>
                  <span className="badge badge-neutral">{ORIGINE_LABELS[c.origine] ?? c.origine}</span>{' '}
                  {formatDateFr(c.declenche_le)}
                </div>
              </div>
            ))}
          </div>
        </Alert>
      ) : dossier.statut_eligibilite_interne === 'eligible' ? (
        <Alert variant="success">Aucun critère éliminatoire détecté.</Alert>
      ) : (
        <Alert variant="info">Éligibilité non encore vérifiée.</Alert>
      )}
    </div>
  )
}
