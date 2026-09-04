/**
 * Barre d'actions du wizard — classe `.wizard-footer` du design system maquette
 * (empilement mobile : action principale en haut via `order`).
 */
export function WizardFooter({
  onBack,
  onDraft,
  onNext,
  nextLabel = 'Suivant',
  showBack = true,
  saving = false,
  advancing = false,
  primaryLarge = false,
}) {
  return (
    <div className="wizard-footer">
      <div className="wizard-footer-back">
        {showBack ? (
          <button type="button" className="btn btn-outline" onClick={onBack} disabled={advancing}>
            <i className="fa-solid fa-arrow-left" aria-hidden="true" /> Précédent
          </button>
        ) : null}
      </div>
      <div className="wizard-footer-actions">
        {onDraft ? (
          <button
            type="button"
            className={`btn btn-ghost wizard-footer-draft${saving ? ' is-loading' : ''}`}
            onClick={onDraft}
            disabled={saving || advancing}
          >
            <i className="fa-solid fa-floppy-disk" aria-hidden="true" /> Enregistrer le brouillon
          </button>
        ) : null}
        <button
          type="button"
          className={`btn btn-primary wizard-footer-primary${primaryLarge ? ' btn-lg' : ''}${advancing ? ' is-loading' : ''}`}
          onClick={onNext}
          disabled={advancing || saving}
        >
          {nextLabel} <i className="fa-solid fa-arrow-right" aria-hidden="true" />
        </button>
      </div>
    </div>
  )
}
