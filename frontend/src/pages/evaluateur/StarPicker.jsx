/**
 * Étoiles MO.04 (Lot 8c-2) — `mo04_note_etoiles`, 0 à 5.
 *
 * La plage 0-5 est une cardinalité D'UI fixe, miroir de la règle backend
 * `EnregistrerEvaluationRequest` (`between:0,5`) — pas un point de barème : le
 * sélecteur affiche des CRANS, jamais leur valeur en points. La conversion
 * étoile → points reste 100% côté `ServiceScoring` (jamais dans ce composant).
 */
const STARS = [1, 2, 3, 4, 5]

export function StarPicker({ value, onChange, disabled }) {
  return (
    <div className="star-select" role="radiogroup" aria-label="Notation qualitative de la motivation">
      {STARS.map((n) => (
        <button
          key={n}
          type="button"
          className={value != null && value >= n ? 'is-active' : ''}
          disabled={disabled}
          aria-pressed={value != null && value >= n}
          aria-label={`${n} étoile${n > 1 ? 's' : ''}`}
          onClick={() => onChange(n)}
        >
          <i className="fa-solid fa-star" aria-hidden="true" />
        </button>
      ))}
    </div>
  )
}
