/**
 * Message d'alerte — réutilise `.alert` + `.alert-{info,success,warning,danger}`
 * du design system maquette. `role="alert"` pour l'annonce lecteur d'écran.
 */
const ICONS = {
  info: 'fa-circle-info',
  success: 'fa-circle-check',
  warning: 'fa-triangle-exclamation',
  danger: 'fa-circle-exclamation',
}

export function Alert({ variant = 'info', title, children }) {
  return (
    <div className={`alert alert-${variant}`} role="alert">
      <i className={`fa-solid ${ICONS[variant] ?? ICONS.info}`} aria-hidden="true" />
      <div>
        {title ? <div className="alert-title">{title}</div> : null}
        {children}
      </div>
    </div>
  )
}
