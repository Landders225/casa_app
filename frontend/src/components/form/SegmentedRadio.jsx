import { useId } from 'react'
import { FieldError } from './FieldError.jsx'

/**
 * Choix Oui/Non (ou 2-3 options courtes) en boutons segmentés — réutilise
 * `.radio-segmented` + `.radio-row` du design system maquette. `<input type=radio>`
 * réel (accessibilité clavier + name/value). Cible tactile ≥ 44px (CSS maquette).
 */
export function SegmentedRadio({ legend, name, options, value, onChange, error, hint }) {
  const groupId = useId()
  return (
    <fieldset className="form-group" style={{ border: 0, padding: 0, margin: '0 0 var(--space-5)' }}>
      <legend className="label" id={groupId}>{legend}</legend>
      <div className="radio-segmented" role="radiogroup" aria-labelledby={groupId}>
        {options.map((o) => {
          const checked = value === o.value
          return (
            <label key={o.value} className={`radio-row${checked ? ' is-checked' : ''}`}>
              <input
                type="radio"
                name={name}
                value={o.value}
                checked={checked}
                onChange={() => onChange(o.value)}
              />
              <span className="body-sm">{o.label}</span>
            </label>
          )
        })}
      </div>
      {hint ? <p className="input-hint">{hint}</p> : null}
      <FieldError>{error}</FieldError>
    </fieldset>
  )
}
