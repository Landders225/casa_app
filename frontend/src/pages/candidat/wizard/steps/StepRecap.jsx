import { Alert } from '../../../../components/ui/Alert.jsx'
import { Checkbox } from '../../../../components/ui/Checkbox.jsx'
import {
  DISPONIBILITE, EXPERIENCE_DOMAINES, EXPERIENCE_DUREES, MOTIVATION,
  NIVEAUX, PIECES_DOSSIER, SCOLAIRE, SOCIO_ECO, stepIndex,
} from '../formStructure.js'
import { submitErrorLabel, submitErrorStep } from '../completeness.js'

const optionLabel = (opts, value) => opts.find((o) => o.value === value)?.label ?? '—'
const yesNo = (v) => (v === 'oui' ? 'Oui' : v === 'non' ? 'Non' : '—')

function Section({ title, stepKey, onJump, children }) {
  return (
    <div className="recap-section">
      <h4>
        {title}
        <button type="button" className="btn btn-link btn-sm" onClick={() => onJump(stepIndex(stepKey))}>
          Modifier
        </button>
      </h4>
      {children}
    </div>
  )
}

const Row = ({ k, v }) => (
  <div className="recap-row">
    <span>{k}</span>
    <span>{v}</span>
  </div>
)

export function StepRecap({ form, profil, onJump, submitErrors, onSubmit, submitting, certified, setCertified }) {
  const { reponses: r, experiences, classement, pieces, candidature } = form

  const missing = submitErrors ? Object.entries(submitErrors).flatMap(([k, v]) => v.map(() => k)).filter((v, i, a) => a.indexOf(v) === i) : []

  return (
    <>
      <h3 style={{ marginBottom: 'var(--space-6)' }}>Récapitulatif de votre candidature</h3>

      {submitErrors ? (
        <div style={{ marginBottom: 'var(--space-6)' }}>
          <Alert variant="danger" title="Dossier incomplet">
            Certaines informations sont manquantes. Corrigez-les puis soumettez à nouveau.
            <ul style={{ marginTop: 'var(--space-3)', display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
              {missing.map((key) => (
                <li key={key} className="flex items-center gap-3" style={{ justifyContent: 'space-between' }}>
                  <span className="body-sm">{submitErrorLabel(key)}</span>
                  <button type="button" className="btn btn-sm btn-outline" onClick={() => onJump(submitErrorStep(key))}>
                    Corriger
                  </button>
                </li>
              ))}
            </ul>
          </Alert>
        </div>
      ) : null}

      <Section title="Identité" stepKey="identite" onJump={onJump}>
        <Row k="Nom complet" v={`${profil?.prenom ?? ''} ${profil?.nom ?? ''}`.trim() || '—'} />
        <Row k="Date de naissance" v={profil?.date_naissance ?? '—'} />
        <Row k="Contact" v={`${profil?.telephone ?? '—'} · ${profil?.email ?? ''}`} />
        <Row k="Ville" v={profil?.ville_residence ?? '—'} />
      </Section>

      <Section title="Filière" stepKey="filiere" onJump={onJump}>
        <Row k="Filière choisie" v={candidature?.filiere?.nom ?? '—'} />
        <Row k="Confirmée" v={candidature?.cqp_confirme ? 'Oui' : 'Non'} />
      </Section>

      <Section title="Profil scolaire" stepKey="scolaire" onJump={onJump}>
        <Row k="Scolarisé actuellement" v={yesNo(r.sc01_scolarise_actuellement)} />
        <Row k="Dernière classe" v={optionLabel(SCOLAIRE.sc02.options, r.sc02_derniere_classe)} />
        <Row k="Diplôme" v="Vérifié par l'équipe d'évaluation" />
      </Section>

      <Section title="Socio-économique" stepKey="socioEco" onJump={onJump}>
        <Row k="Situation d'emploi" v={optionLabel(SOCIO_ECO.se03.options, r.se03_situation_emploi)} />
        <Row k="Orphelin(e)" v={yesNo(r.se02_orphelin)} />
        <Row k="Soutien du ménage" v={yesNo(r.se06_soutien_menage)} />
      </Section>

      <Section title="Expérience" stepKey="experience" onJump={onJump}>
        {experiences.length === 0 ? (
          <Row k="Expérience" v="Aucune" />
        ) : (
          experiences.map((e, i) => (
            <Row
              key={e.id}
              k={`Expérience ${i + 1}`}
              v={`${optionLabel(EXPERIENCE_DOMAINES, e.domaine)} · ${optionLabel(EXPERIENCE_DUREES, e.duree_categorie)}`}
            />
          ))
        )}
      </Section>

      <Section title="Langues & informatique" stepKey="langues" onJump={onJump}>
        <Row k="Français écrit" v={NIVEAUX[r.langue_ecrit] ?? '—'} />
        <Row k="Français parlé" v={NIVEAUX[r.langue_parle] ?? '—'} />
        <Row k="Word" v={NIVEAUX[r.info_word] ?? '—'} />
      </Section>

      <Section title="Motivation" stepKey="motivation" onJump={onJump}>
        <Row k="1er choix" v={classement[0]?.nom ?? '—'} />
        <Row
          k="Lettre de motivation"
          v={r[MOTIVATION.mo04.field] ? `${r[MOTIVATION.mo04.field].slice(0, 60)}…` : '—'}
        />
      </Section>

      <Section title="Disponibilité" stepKey="disponibilite" onJump={onJump}>
        <Row k="Disponible lun-ven" v={yesNo(r.di01_disponible_lun_ven)} />
        <Row k="Contraintes" v={optionLabel(DISPONIBILITE.di02.options, r.di02_contraintes)} />
        <Row k="Accès au Plateau" v={yesNo(r.acces_plateau)} />
        <Row k="Accès aux 2 Plateaux Vallons" v={yesNo(r.acces_deux_plateaux_vallons)} />
      </Section>

      <Section title="Documents" stepKey="documents" onJump={onJump}>
        <Row k="Pièces déposées" v={`${Object.keys(pieces).length} / ${PIECES_DOSSIER.length}`} />
      </Section>

      <div style={{ margin: 'var(--space-6) 0' }}>
        <Checkbox
          label="Je certifie l'exactitude des informations fournies dans ce dossier de candidature."
          checked={certified}
          onChange={setCertified}
        />
      </div>

      <button
        type="button"
        className={`btn btn-primary btn-lg btn-block${submitting ? ' is-loading' : ''}`}
        onClick={onSubmit}
        disabled={submitting}
      >
        <i className="fa-solid fa-paper-plane" aria-hidden="true" /> Soumettre ma candidature
      </button>
    </>
  )
}
