import { useId } from 'react'
import { FieldError } from './FieldError.jsx'

/**
 * Zone de texte avec compteur de caractères — réutilise `.textarea` +
 * `.textarea-counter` du design system.
 */
export function CharCounterTextarea({ label, value, onChange, maxLength, hint, placeholder, error, rows = 4 }) {
  const id = useId()
  const len = (value || '').length
  return (
    <div className="form-group" style={{ marginBottom: 0 }}>
      <label className="label" htmlFor={id}>{label}</label>
      <textarea
        id={id}
        className={`textarea${error ? ' is-error' : ''}`}
        rows={rows}
        maxLength={maxLength}
        value={value || ''}
        placeholder={placeholder}
        onChange={(e) => onChange(e.target.value)}
        aria-invalid={error ? 'true' : undefined}
      />
      <div className="flex justify-between" style={{ marginTop: 4, gap: 'var(--space-3)' }}>
        {hint ? <div className="input-hint">{hint}</div> : <span />}
        <div className="caption textarea-counter">{len} / {maxLength}</div>
      </div>
      <FieldError>{error}</FieldError>
    </div>
  )
}
