import { RadioCardList } from '../../../../components/form/RadioCardList.jsx'
import { SegmentedRadio } from '../../../../components/form/SegmentedRadio.jsx'
import { DISPONIBILITE } from '../formStructure.js'

export function StepDisponibilite({ form, errors }) {
  const { reponses, setReponse } = form
  const seg = (q, hint) => (
    <SegmentedRadio
      legend={q.label}
      name={q.field}
      options={q.options}
      value={reponses[q.field]}
      onChange={(v) => setReponse(q.field, v)}
      error={errors[q.field]}
      hint={hint}
    />
  )

  return (
    <>
      <h3 style={{ marginBottom: 'var(--space-5)' }}>Disponibilité</h3>
      {seg(DISPONIBILITE.di01)}
      <RadioCardList
        legend={DISPONIBILITE.di02.label}
        name={DISPONIBILITE.di02.field}
        options={DISPONIBILITE.di02.options}
        value={reponses[DISPONIBILITE.di02.field]}
        onChange={(v) => setReponse(DISPONIBILITE.di02.field, v)}
        error={errors[DISPONIBILITE.di02.field]}
      />
      {seg(DISPONIBILITE.di03)}
      {seg(DISPONIBILITE.di04)}
      {seg(DISPONIBILITE.di05, 'Il suffit de pouvoir vous rendre sur l’un des deux sites.')}
    </>
  )
}
