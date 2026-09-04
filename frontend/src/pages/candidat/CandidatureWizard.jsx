import { useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { StepProgress } from '../../components/form/StepProgress.jsx'
import { WizardFooter } from '../../components/form/WizardFooter.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { FullPageSpinner } from '../../components/ui/Spinner.jsx'
import { useAuth } from '../../auth/useAuth.js'
import { ApiError } from '../../lib/ApiError.js'
import { paths } from '../../routing/routes.js'
import './CandidatureWizard.css'

import { STEPS } from './wizard/formStructure.js'
import { stepErrors } from './wizard/completeness.js'
import { useCandidatureForm } from './wizard/useCandidatureForm.js'
import { StepIdentite } from './wizard/steps/StepIdentite.jsx'
import { StepFiliere } from './wizard/steps/StepFiliere.jsx'
import { StepScolaire } from './wizard/steps/StepScolaire.jsx'
import { StepSocioEco } from './wizard/steps/StepSocioEco.jsx'
import { StepExperience } from './wizard/steps/StepExperience.jsx'
import { StepLangues } from './wizard/steps/StepLangues.jsx'
import { StepMotivation } from './wizard/steps/StepMotivation.jsx'
import { StepDisponibilite } from './wizard/steps/StepDisponibilite.jsx'
import { StepDocuments } from './wizard/steps/StepDocuments.jsx'
import { StepRecap } from './wizard/steps/StepRecap.jsx'

const PROFIL_FIELDS = ['prenom', 'nom', 'sexe', 'date_naissance', 'cni', 'telephone', 'ville_residence']

function AlreadySubmitted() {
  return (
    <AppShell title="Formulaire de candidature">
      <div className="empty-state card" style={{ maxWidth: 520, margin: '0 auto' }}>
        <div className="empty-icon">
          <i className="fa-solid fa-lock" aria-hidden="true" />
        </div>
        <h3>Votre dossier a déjà été soumis</h3>
        <p>Il n'est plus modifiable. Suivez son avancement depuis votre tableau de bord.</p>
        <Link className="btn btn-primary" to={paths.candidat}>Suivre ma candidature</Link>
      </div>
    </AppShell>
  )
}

function SuccessModal({ numero }) {
  return (
    <div className="modal-overlay" role="dialog" aria-modal="true" aria-label="Candidature soumise">
      <div className="modal">
        <div className="modal-body" style={{ padding: 'var(--space-8) var(--space-6)', textAlign: 'center' }}>
          <div
            className="empty-icon"
            style={{ margin: '0 auto var(--space-5)', background: 'var(--casa-success-100)', color: 'var(--casa-success-700)' }}
          >
            <i className="fa-solid fa-check" aria-hidden="true" />
          </div>
          <h3>Candidature soumise avec succès !</h3>
          <p className="text-muted" style={{ marginTop: 'var(--space-2)' }}>Votre numéro de dossier :</p>
          <p className="h3 text-primary-brand" style={{ marginTop: 'var(--space-2)' }}>{numero}</p>
          <p className="caption" style={{ marginTop: 'var(--space-3)' }}>
            Vous serez notifié(e) à chaque étape de l'instruction de votre dossier.
          </p>
        </div>
        <div className="modal-footer" style={{ justifyContent: 'center' }}>
          <Link className="btn btn-primary btn-lg" to={paths.candidat}>
            Suivre ma candidature <i className="fa-solid fa-arrow-right" aria-hidden="true" />
          </Link>
        </div>
      </div>
    </div>
  )
}

export function CandidatureWizard() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const form = useCandidatureForm()

  const [step, setStep] = useState(0)
  const [clientErrors, setClientErrors] = useState({})
  const [advancing, setAdvancing] = useState(false)
  const [flash, setFlash] = useState(null)
  const [submitErrors, setSubmitErrors] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [success, setSuccess] = useState(null)
  const [certified, setCertified] = useState(false)

  const [profilDraft, setProfilDraft] = useState(() =>
    Object.fromEntries(PROFIL_FIELDS.map((f) => [f, user?.profil?.[f] ?? ''])),
  )
  const [picked, setPicked] = useState(null)
  const [confirmChecked, setConfirmChecked] = useState(false)

  const key = STEPS[step].key

  const snapshot = useMemo(
    () => ({
      profil: { ...user?.profil, ...(key === 'identite' ? profilDraft : {}) },
      candidature: form.candidature,
      reponses: form.reponses,
      experiences: form.experiences,
      pieces: form.pieces,
    }),
    [user?.profil, profilDraft, key, form.candidature, form.reponses, form.experiences, form.pieces],
  )

  if (form.status === 'loading') return <FullPageSpinner />
  if (form.status === 'submitted') return <AlreadySubmitted />
  if (form.status === 'error') {
    return (
      <AppShell title="Formulaire de candidature">
        <Alert variant="warning">Le formulaire n'a pas pu être chargé. Réessayez plus tard.</Alert>
      </AppShell>
    )
  }

  const mergedErrors = { ...clientErrors, ...form.serverErrors }

  async function commitStep() {
    switch (key) {
      case 'identite': {
        try {
          await form.updateProfil(profilDraft)
        } catch (err) {
          if (err instanceof ApiError && err.kind === 'validation') {
            setClientErrors(Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])))
            if (!err.errors || Object.keys(err.errors).length === 0) setFlash(err.message)
            throw err
          }
          throw err
        }
        return
      }
      case 'filiere': {
        const cand = form.candidature ?? (await form.createCandidature(picked))
        await form.confirmFiliere(cand)
        return
      }
      case 'motivation':
        await form.saveReponses()
        await form.saveClassement()
        return
      case 'scolaire':
      case 'socioEco':
      case 'langues':
      case 'disponibilite':
        await form.saveReponses()
        return
      default:
        // experience / documents : déjà persistés au fil de l'eau.
    }
  }

  async function goNext() {
    setFlash(null)
    let errs
    if (key === 'filiere') {
      // Sur cette étape, la confirmation n'est envoyée au backend qu'au « Suivant » :
      // on ne peut pas se fier à `candidature.cqp_confirme` pour ouvrir la porte,
      // on gate sur le choix + la case cochée (état local).
      errs = {}
      if (!form.candidature && !picked) errs.picked = 'Choisissez une filière.'
      if (!form.candidature?.cqp_confirme && !confirmChecked) {
        errs.cqp_confirme = 'Confirmez votre filière pour continuer.'
      }
    } else {
      errs = stepErrors(key, snapshot)
    }
    if (Object.keys(errs).length) {
      setClientErrors(errs)
      return
    }
    setClientErrors({})
    setAdvancing(true)
    try {
      await commitStep()
      setStep((s) => Math.min(s + 1, STEPS.length - 1))
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        return // le hook a basculé en 'submitted'
      }
      setFlash(
        err instanceof ApiError && err.kind === 'validation'
          ? 'Certaines informations sont invalides — corrigez les champs signalés.'
          : 'La sauvegarde a échoué. Vérifiez votre connexion et réessayez avant de continuer.',
      )
    } finally {
      setAdvancing(false)
    }
  }

  function goBack() {
    setFlash(null)
    setClientErrors({})
    setStep((s) => Math.max(s - 1, 0))
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  function jumpTo(i) {
    setFlash(null)
    setClientErrors({})
    setStep(i)
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  async function saveDraft() {
    setFlash(null)
    try {
      if (key === 'identite') await form.updateProfil(profilDraft)
      else if (key === 'motivation') {
        await form.saveReponses()
        await form.saveClassement()
      } else await form.saveReponses()
      setFlash({ ok: true, text: 'Brouillon enregistré. Vous pourrez reprendre à tout moment.' })
    } catch {
      setFlash('Le brouillon n\'a pas pu être enregistré. Réessayez.')
    }
  }

  async function handleSubmit() {
    if (!certified) {
      setFlash('Cochez la case de certification pour soumettre.')
      return
    }
    setSubmitting(true)
    setSubmitErrors(null)
    setFlash(null)
    try {
      // Sécurité : purge d'un éventuel brouillon non sauvegardé.
      await form.saveReponses()
      await form.saveClassement()
      const res = await form.submit()
      if (res.data) {
        setSuccess(res.data.numero_dossier)
      } else if (res.errors) {
        setSubmitErrors(res.errors)
        setFlash('Votre dossier est incomplet — voir le détail ci-dessus.')
      } else if (res.conflict) {
        navigate(paths.candidat, { replace: true })
      }
    } catch {
      setFlash('La soumission a échoué. Réessayez.')
    } finally {
      setSubmitting(false)
    }
  }

  const StepComponent = {
    identite: <StepIdentite email={user?.profil?.email ?? user?.email ?? ''} draft={profilDraft} setDraft={setProfilDraft} errors={mergedErrors} />,
    filiere: (
      <StepFiliere
        form={form}
        picked={picked ?? form.candidature?.filiere?.id ?? null}
        setPicked={setPicked}
        confirmChecked={confirmChecked}
        setConfirmChecked={setConfirmChecked}
        errors={mergedErrors}
      />
    ),
    scolaire: <StepScolaire form={form} errors={mergedErrors} />,
    socioEco: <StepSocioEco form={form} errors={mergedErrors} />,
    experience: <StepExperience form={form} errors={mergedErrors} />,
    langues: <StepLangues form={form} errors={mergedErrors} />,
    motivation: <StepMotivation form={form} errors={mergedErrors} />,
    disponibilite: <StepDisponibilite form={form} errors={mergedErrors} />,
    documents: <StepDocuments form={form} errors={mergedErrors} />,
    recap: (
      <StepRecap
        form={form}
        profil={{ ...user?.profil, ...profilDraft, email: user?.profil?.email ?? user?.email }}
        onJump={jumpTo}
        submitErrors={submitErrors}
        onSubmit={handleSubmit}
        submitting={submitting}
        certified={certified}
        setCertified={setCertified}
      />
    ),
  }[key]

  return (
    <AppShell title="Formulaire de candidature">
      <div className="form-shell">
        <StepProgress index={step} />

        {flash ? (
          <div style={{ marginBottom: 'var(--space-5)' }}>
            <Alert variant={flash.ok ? 'success' : 'danger'}>{flash.ok ? flash.text : flash}</Alert>
          </div>
        ) : null}

        <div className="card page-enter">{StepComponent}</div>

        {key !== 'recap' ? (
          <WizardFooter
            onBack={goBack}
            onDraft={saveDraft}
            onNext={goNext}
            showBack={step > 0}
            saving={form.saving}
            advancing={advancing}
          />
        ) : (
          <div className="wizard-footer" style={{ justifyContent: 'flex-start' }}>
            <button type="button" className="btn btn-outline" onClick={goBack}>
              <i className="fa-solid fa-arrow-left" aria-hidden="true" /> Précédent
            </button>
            <button
              type="button"
              className={`btn btn-ghost${form.saving ? ' is-loading' : ''}`}
              onClick={saveDraft}
              disabled={form.saving}
            >
              <i className="fa-solid fa-floppy-disk" aria-hidden="true" /> Enregistrer le brouillon
            </button>
          </div>
        )}
      </div>

      {success ? <SuccessModal numero={success} /> : null}
    </AppShell>
  )
}
