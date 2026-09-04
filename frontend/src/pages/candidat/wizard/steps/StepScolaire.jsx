import { Alert } from '../../../../components/ui/Alert.jsx'
import { CharCounterTextarea } from '../../../../components/form/CharCounterTextarea.jsx'
import { RadioCardList } from '../../../../components/form/RadioCardList.jsx'
import { SegmentedRadio } from '../../../../components/form/SegmentedRadio.jsx'
import { SCOLAIRE } from '../formStructure.js'

export function StepScolaire({ form, errors }) {
  const { reponses, setReponse } = form
  const err = (f) => errors[f]
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

  const dejaBeneficie = reponses.sc06_deja_beneficie_formation === 'oui'
  const nonACharge = reponses.sc05_beneficiaire_formation_actuelle === 'non'

  return (
    <>
      <h3 style={{ marginBottom: 'var(--space-5)' }}>Profil scolaire</h3>

      {seg(SCOLAIRE.sc01)}
      <RadioCardList
        legend={SCOLAIRE.sc02.label}
        name={SCOLAIRE.sc02.field}
        options={SCOLAIRE.sc02.options}
        value={reponses[SCOLAIRE.sc02.field]}
        onChange={(v) => setReponse(SCOLAIRE.sc02.field, v)}
        error={err(SCOLAIRE.sc02.field)}
      />
      {seg(SCOLAIRE.sc03)}
      {seg(SCOLAIRE.sc05)}

      {nonACharge ? (
        <>
          {seg(SCOLAIRE.sc06)}
          {dejaBeneficie ? (
            <>
              <CharCounterTextarea
                label={SCOLAIRE.sc07.label}
                maxLength={SCOLAIRE.sc07.maxLength}
                rows={2}
                value={reponses.sc07_filiere_suivie}
                onChange={(v) => setReponse('sc07_filiere_suivie', v)}
                error={err('sc07_filiere_suivie')}
              />
              <div style={{ height: 'var(--space-5)' }} />
              {seg(SCOLAIRE.sc08)}
              {reponses.sc08_mene_a_terme === 'non' ? (
                <CharCounterTextarea
                  label={SCOLAIRE.sc09.label}
                  maxLength={SCOLAIRE.sc09.maxLength}
                  rows={2}
                  value={reponses.sc09_motif_non_achevement}
                  onChange={(v) => setReponse('sc09_motif_non_achevement', v)}
                  error={err('sc09_motif_non_achevement')}
                />
              ) : null}
              {reponses.sc08_mene_a_terme === 'oui' ? (
                <p className="input-hint">
                  <i className="fa-solid fa-paperclip" aria-hidden="true" /> Le justificatif d'achèvement sera à
                  déposer à l'étape « Pièces justificatives ».
                </p>
              ) : null}
            </>
          ) : null}
        </>
      ) : null}

      <div style={{ marginTop: 'var(--space-5)' }}>
        <Alert variant="info">
          Votre plus haut diplôme sera vérifié par l'équipe d'évaluation à partir du document déposé à l'étape
          « Pièces justificatives » — aucune saisie n'est requise ici.
        </Alert>
      </div>
    </>
  )
}
