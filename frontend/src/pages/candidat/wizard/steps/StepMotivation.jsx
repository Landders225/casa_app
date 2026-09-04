import { CharCounterTextarea } from '../../../../components/form/CharCounterTextarea.jsx'
import { RankList } from '../../../../components/form/RankList.jsx'
import { MOTIVATION } from '../formStructure.js'

export function StepMotivation({ form, errors }) {
  const { reponses, setReponse, classement, reorderClassement } = form
  const q = MOTIVATION.mo04

  return (
    <>
      <h3 style={{ marginBottom: 'var(--space-5)' }}>Motivation</h3>

      <div className="form-group">
        <span className="label">Classez les filières par ordre de préférence</span>
        <p className="caption" style={{ marginBottom: 'var(--space-3)' }}>
          Glissez-déposez pour réordonner, ou utilisez les flèches. 1 = votre premier choix.
        </p>
        {classement.length === 5 ? (
          <RankList items={classement} onReorder={reorderClassement} />
        ) : (
          <p className="caption">Le classement sera disponible après la confirmation de votre filière.</p>
        )}
      </div>

      <CharCounterTextarea
        label={q.label}
        maxLength={q.maxLength}
        rows={4}
        hint={q.hint}
        placeholder={q.placeholder}
        value={reponses[q.field]}
        onChange={(v) => setReponse(q.field, v)}
        error={errors[q.field]}
      />
    </>
  )
}
