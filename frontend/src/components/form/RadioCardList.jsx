import { useId } from 'react'
import { FieldError } from './FieldError.jsx'

/**
 * Liste de 3 à 7 options empilées (libellés longs) — réutilise `.radio-row` du
 * design system maquette (une carte par ligne, cliquable en entier).
 */
export function RadioCardList({ legend, name, options, value, onChange, error, hint }) {
  const groupId = useId()
  return (
    <fieldset className="form-group" style={{ border: 0, padding: 0, margin: '0 0 var(--space-5)' }}>
      <legend className="label" id={groupId}>{legend}</legend>
      <div role="radiogroup" aria-labelledby={groupId} style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
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
