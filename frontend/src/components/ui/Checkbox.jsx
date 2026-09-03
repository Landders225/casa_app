import { useId } from 'react'

/**
 * Case à cocher — réutilise `.check-row` du design system maquette.
 * `error` (chaîne) met la ligne en état d'erreur et l'annonce (`aria-describedby`).
 */
export function Checkbox({ label, checked, onChange, error = null, required = false }) {
  const id = useId()
  const errId = `${id}-error`

  return (
    <div>
      <label className={`check-row${checked ? ' is-checked' : ''}`} htmlFor={id}>
        <input
          id={id}
          type="checkbox"
          checked={checked}
          onChange={(e) => onChange(e.target.checked)}
          required={required}
          aria-invalid={error ? 'true' : undefined}
          aria-describedby={error ? errId : undefined}
        />
        <span className="body-sm">{label}</span>
      </label>
      {error ? (
        <p className="input-error" id={errId}>
          <i className="fa-solid fa-circle-exclamation" aria-hidden="true" /> {Array.isArray(error) ? error[0] : error}
        </p>
      ) : null}
    </div>
  )
}
