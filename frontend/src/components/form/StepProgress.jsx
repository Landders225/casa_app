import { STEPS } from '../../pages/candidat/wizard/formStructure.js'

/** En-tête de progression du wizard — `.step-progress-head` + `.step-dots`. */
export function StepProgress({ index }) {
  return (
    <div className="step-progress-head">
      <div className="flex justify-between items-center">
        <span className="eyebrow">Étape {index + 1} / {STEPS.length}</span>
        <span className="caption">{STEPS[index].label}</span>
      </div>
      <div className="step-dots" aria-hidden="true">
        {STEPS.map((s, i) => (
          <span key={s.key} className={i < index ? 'is-done' : i === index ? 'is-current' : ''} />
        ))}
      </div>
    </div>
  )
}
