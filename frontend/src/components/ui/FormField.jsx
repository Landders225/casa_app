import { useId } from 'react'

/**
 * Champ de formulaire — réutilise `.form-group` / `.label` / `.input` /
 * `.input-icon-wrap` / `.input-error` du design system maquette.
 *
 * `error` (chaîne ou tableau de chaînes) affiche le premier message et met le
 * champ en état `is-error` + `aria-invalid`.
 */
export function FormField({
  label,
  type = 'text',
  icon = null,
  error = null,
  hint = null,
  inputProps = {},
}) {
  const id = useId()
  const errId = `${id}-error`
  const hintId = `${id}-hint`
  const message = Array.isArray(error) ? error[0] : error
  const describedBy = [message ? errId : null, hint ? hintId : null].filter(Boolean).join(' ') || undefined

  const field = (
    <input
      id={id}
      type={type}
      className={`input${message ? ' is-error' : ''}`}
      aria-invalid={message ? 'true' : undefined}
      aria-describedby={describedBy}
      {...inputProps}
    />
  )

  return (
    <div className="form-group">
      <label className="label" htmlFor={id}>
        {label}
      </label>
      {icon ? (
        <div className="input-icon-wrap">
          <i className={`fa-solid ${icon}`} aria-hidden="true" />
          {field}
        </div>
      ) : (
        field
      )}
      {hint ? (
        <p className="input-hint" id={hintId}>
          {hint}
        </p>
      ) : null}
      {message ? (
        <p className="input-error" id={errId}>
          <i className="fa-solid fa-circle-exclamation" aria-hidden="true" /> {message}
        </p>
      ) : null}
    </div>
  )
}
