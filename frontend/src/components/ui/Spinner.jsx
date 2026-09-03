/** Indicateur de chargement — réutilise `.spinner` du design system maquette. */
export function Spinner({ large = false, label = 'Chargement…' }) {
  return (
    <span
      className={large ? 'spinner spinner-lg' : 'spinner'}
      role="status"
      aria-label={label}
    />
  )
}

/** Écran de chargement plein page (boot de l'app). */
export function FullPageSpinner() {
  return (
    <div className="app-boot">
      <Spinner large />
    </div>
  )
}
