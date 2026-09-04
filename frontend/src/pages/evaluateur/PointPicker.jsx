/**
 * Sélecteur de points 0..max pour une sous-note d'entretien (Lot 8c-2).
 *
 * `max` vient TOUJOURS de la réponse API (`sous_notes[].max`) — la plage de
 * boutons est construite dynamiquement à partir de cette valeur, jamais d'une
 * constante locale : aucun maximum de sous-critère n'est codé en dur ici.
 */
export function PointPicker({ label, max, value, onChange, disabled }) {
  const options = Array.from({ length: max + 1 }, (_, i) => i)
  return (
    <div className="segmented" role="radiogroup" aria-label={label}>
      {options.map((n) => (
        <button
          key={n}
          type="button"
          className={value === n ? 'is-selected' : ''}
          disabled={disabled}
          aria-pressed={value === n}
          onClick={() => onChange(n)}
        >
          {n}
        </button>
      ))}
    </div>
  )
}
