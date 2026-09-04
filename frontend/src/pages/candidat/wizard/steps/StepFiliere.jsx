import { Checkbox } from '../../../../components/ui/Checkbox.jsx'
import { filiereIcon } from '../../../../lib/filiereIcons.js'

/**
 * Étape 2 — filière. Choisie ici (l'inscription 8b-1 ne la collecte pas, D-7-1),
 * puis confirmée. La confirmation est IRRÉVERSIBLE (`POST .../confirmer-filiere`).
 */
export function StepFiliere({ form, picked, setPicked, confirmChecked, setConfirmChecked, errors }) {
  const { candidature, filieres } = form
  const dejaConfirme = candidature?.cqp_confirme
  const filiereFixee = candidature?.filiere ?? null

  // Filière verrouillée dès que la candidature existe (pas d'endpoint de changement).
  const locked = Boolean(candidature)
  const currentCode = filiereFixee?.code

  return (
    <>
      <h3 style={{ marginBottom: 'var(--space-2)' }}>Filière CQP souhaitée</h3>
      <p className="caption" style={{ marginBottom: 'var(--space-5)' }}>
        {locked
          ? 'Une fois confirmée, votre filière ne pourra plus être modifiée.'
          : 'Choisissez la filière que vous souhaitez intégrer en priorité. Une fois confirmée, elle ne pourra plus être modifiée.'}
      </p>

      {locked ? (
        <div className="select-card is-selected" style={{ cursor: 'default' }}>
          <div className="cqp-icon" style={{ width: 44, height: 44, fontSize: '1.1rem', marginBottom: 'var(--space-3)' }}>
            <i className={`fa-solid ${filiereIcon(currentCode)}`} aria-hidden="true" />
          </div>
          <h4>{filiereFixee?.nom}</h4>
        </div>
      ) : (
        <div className="grid grid-2">
          {filieres.filter((f) => f.actif).map((f) => (
            <button
              key={f.id}
              type="button"
              className={`select-card${picked === f.id ? ' is-selected' : ''}`}
              aria-pressed={picked === f.id}
              onClick={() => setPicked(f.id)}
            >
              <div className="cqp-icon" style={{ width: 44, height: 44, fontSize: '1.1rem', marginBottom: 'var(--space-3)' }}>
                <i className={`fa-solid ${filiereIcon(f.code)}`} aria-hidden="true" />
              </div>
              <h4>{f.nom}</h4>
              <p className="caption" style={{ marginTop: 4 }}>{f.description}</p>
            </button>
          ))}
        </div>
      )}

      {errors.picked ? <p className="input-error" role="alert">{errors.picked}</p> : null}

      <div style={{ marginTop: 'var(--space-5)' }}>
        {dejaConfirme ? (
          <p className="body-sm">
            <i className="fa-solid fa-circle-check text-primary-brand" aria-hidden="true" /> Filière confirmée.
          </p>
        ) : (
          <Checkbox
            label={`Je confirme que ${filiereFixee?.nom || 'cette filière'} est bien la filière que je souhaite intégrer, et je comprends que je ne pourrai plus la modifier.`}
            checked={confirmChecked}
            onChange={setConfirmChecked}
            error={errors.cqp_confirme}
            required
          />
        )}
      </div>
    </>
  )
}
