/** Message d'erreur sous un contrôle — `.input-error` du design system. */
export function FieldError({ children }) {
  if (!children) return null
  return (
    <p className="input-error" role="alert">
      <i className="fa-solid fa-circle-exclamation" aria-hidden="true" /> {children}
    </p>
  )
}
