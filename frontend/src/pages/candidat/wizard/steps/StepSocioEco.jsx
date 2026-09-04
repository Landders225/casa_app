import { RadioCardList } from '../../../../components/form/RadioCardList.jsx'
import { SegmentedRadio } from '../../../../components/form/SegmentedRadio.jsx'
import { SOCIO_ECO } from '../formStructure.js'

export function StepSocioEco({ form, errors }) {
  const { reponses, setReponse } = form
  const err = (f) => errors[f]

  const cards = (q) => (
    <RadioCardList
      legend={q.label}
      name={q.field}
      options={q.options}
      value={reponses[q.field]}
      onChange={(v) => setReponse(q.field, v)}
      error={err(q.field)}
      hint={q.optional ? 'Facultatif' : undefined}
    />
  )
  const seg = (q) => (
    <SegmentedRadio
      legend={q.label}
      name={q.field}
      options={q.options}
      value={reponses[q.field]}
      onChange={(v) => setReponse(q.field, v)}
      error={err(q.field)}
    />
  )

  return (
    <>
      <h3 style={{ marginBottom: 'var(--space-5)' }}>Situation socio-économique</h3>
      {cards(SOCIO_ECO.se01)}
      {seg(SOCIO_ECO.se02)}
      {cards(SOCIO_ECO.se03)}
      {reponses.se03_situation_emploi === 'sans_emploi' ? cards(SOCIO_ECO.se04) : null}
      {cards(SOCIO_ECO.se05)}
      {seg(SOCIO_ECO.se06)}
    </>
  )
}
