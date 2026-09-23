import { CharCounterTextarea } from '../../../../components/form/CharCounterTextarea.jsx'
import { RankList } from '../../../../components/form/RankList.jsx'
import { MOTIVATION } from '../formStructure.js'

export function StepMotivation({ form, errors }) {
  const { reponses, setReponse, classement, reorderClassement, candidature } = form
  const q = MOTIVATION.mo04
  // La filière confirmée est TOUJOURS en tête du classement (Lot C, cf.
  // `classementFromResource`) — `lockedId` fige cette ligne dans `RankList`,
  // les 4 autres restent classables normalement.
  const filierePrincipaleId = candidature?.filiere?.id ?? null

  return (
    <>
      <h3 style={{ marginBottom: 'var(--space-5)' }}>Motivation</h3>

      <div className="form-group">
        <span className="label">Classement des filières</span>
        <p className="caption" style={{ marginBottom: 'var(--space-3)' }}>
          Classez les autres filières selon votre préférence. Ce classement ne sera utilisé que si des places se
          libèrent dans votre filière principale.
        </p>
        {classement.length === 5 ? (
          <RankList items={classement} onReorder={reorderClassement} lockedId={filierePrincipaleId} />
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
