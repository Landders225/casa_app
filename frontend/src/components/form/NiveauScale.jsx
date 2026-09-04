import { FieldError } from './FieldError.jsx'
import { NIVEAUX } from '../../pages/candidat/wizard/formStructure.js'

/**
 * Échelle d'auto-évaluation 0-3 (Débutant → Avancé) — réutilise `.segmented` du
 * design system. Le CSS du wizard (CandidatureWizard.css) le fait replier en
 * grille 2×2 sous 420px avec des cibles ≥ 44px (fidèle à candidature.html).
 */
export function NiveauScale({ legend, value, onChange, error }) {
  return (
    <fieldset className="form-group" style={{ border: 0, padding: 0, margin: '0 0 var(--space-4)' }}>
      <legend className="label">{legend}</legend>
      <div className="segmented" role="radiogroup" aria-label={legend}>
        {NIVEAUX.map((label, i) => (
          <button
            key={label}
            type="button"
            role="radio"
            aria-checked={value === i}
            className={value === i ? 'is-selected' : ''}
            onClick={() => onChange(i)}
          >
            {label}
          </button>
        ))}
      </div>
      <FieldError>{error}</FieldError>
    </fieldset>
  )
}
