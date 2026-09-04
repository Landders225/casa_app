import { NiveauScale } from '../../../../components/form/NiveauScale.jsx'
import { LANGUES } from '../formStructure.js'

export function StepLangues({ form, errors }) {
  const { reponses, setReponse } = form
  return (
    <>
      <h3 style={{ marginBottom: 'var(--space-2)' }}>Langues & compétences informatiques</h3>
      <p className="caption" style={{ marginBottom: 'var(--space-5)' }}>
        Évaluez vous-même votre niveau pour chaque compétence.
      </p>
      {LANGUES.map((row) => (
        <NiveauScale
          key={row.field}
          legend={row.label}
          value={reponses[row.field]}
          onChange={(v) => setReponse(row.field, v)}
          error={errors[row.field]}
        />
      ))}
    </>
  )
}
