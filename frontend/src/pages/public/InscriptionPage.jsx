import { useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { PublicHeader } from '../../components/public/PublicHeader.jsx'
import { PublicFooter } from '../../components/public/PublicFooter.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { Checkbox } from '../../components/ui/Checkbox.jsx'
import { FormField } from '../../components/ui/FormField.jsx'
import { useAuth } from '../../auth/useAuth.js'
import { ApiError } from '../../lib/ApiError.js'
import { paths } from '../../routing/routes.js'
import './InscriptionPage.css'

const EMPTY = {
  prenom: '', nom: '', date_naissance: '', sexe: '', cni: '', telephone: '',
  ville_residence: '', email: '', password: '', password_confirmation: '',
}

function computeAge(iso) {
  if (!iso) return null
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return null
  const now = new Date()
  let age = now.getFullYear() - d.getFullYear()
  const m = now.getMonth() - d.getMonth()
  if (m < 0 || (m === 0 && now.getDate() < d.getDate())) age -= 1
  return age
}

export function InscriptionPage() {
  const { register } = useAuth()
  const navigate = useNavigate()

  const [form, setForm] = useState(EMPTY)
  const [residenceCi, setResidenceCi] = useState(false)
  const [cgu, setCgu] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState(null)
  const [fieldErrors, setFieldErrors] = useState({})

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }))
  const age = useMemo(() => computeAge(form.date_naissance), [form.date_naissance])
  const ageWarning = age !== null && (age < 18 || age > 30)

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (submitting) return
    setFormError(null)
    setFieldErrors({})

    if (form.password !== form.password_confirmation) {
      setFieldErrors({ password_confirmation: ['Les deux mots de passe ne correspondent pas.'] })
      return
    }

    setSubmitting(true)
    try {
      await register({
        ...form,
        residence_ci: residenceCi,
        cgu,
      })
      navigate(paths.candidat, { replace: true })
    } catch (err) {
      if (err instanceof ApiError) {
        if (err.kind === 'validation') {
          const errs = err.errors && Object.keys(err.errors).length ? err.errors : null
          if (errs) {
            setFieldErrors(errs)
            // Champ `password` porte aussi l'erreur de confirmation côté backend.
            if (errs.password && !errs.password_confirmation) {
              setFieldErrors({ ...errs, password_confirmation: errs.password })
            }
          } else {
            // Âge / résidence : abort(422, message) sans `errors`.
            setFormError(err.message)
          }
        } else if (err.kind === 'rate_limited') {
          setFormError('Trop de tentatives de création de compte. Réessayez dans une minute.')
        } else if (err.kind === 'network') {
          setFormError('Impossible de contacter le serveur. Vérifiez votre connexion.')
        } else {
          setFormError("La création du compte a échoué. Réessayez.")
        }
      } else {
        setFormError("La création du compte a échoué. Réessayez.")
      }
      setSubmitting(false)
    }
  }

  return (
    <>
      <PublicHeader />
      <main className="signup-wrap page-enter">
        <div className="stepper">
          <div className="step is-active">
            <div className="step-circle">1</div>
            <span className="step-label">Votre compte</span>
            <div className="step-line" />
          </div>
          <div className="step">
            <div className="step-circle">2</div>
            <span className="step-label">Votre candidature</span>
          </div>
        </div>

        <h1 className="h2">Créer votre compte candidat</h1>
        <p className="text-muted" style={{ marginTop: 'var(--space-2)', marginBottom: 'var(--space-6)' }}>
          Ces informations servent à vérifier automatiquement votre éligibilité au programme. Vous choisirez
          votre filière et compléterez votre dossier juste après.
        </p>

        {formError ? (
          <div style={{ marginBottom: 'var(--space-4)' }}>
            <Alert variant="danger" title="Création impossible">{formError}</Alert>
          </div>
        ) : null}

        <form onSubmit={handleSubmit} noValidate>
          <div className="form-row">
            <FormField
              label="Prénom"
              error={fieldErrors.prenom}
              inputProps={{ value: form.prenom, onChange: set('prenom'), autoComplete: 'given-name', required: true }}
            />
            <FormField
              label="Nom"
              error={fieldErrors.nom}
              inputProps={{ value: form.nom, onChange: set('nom'), autoComplete: 'family-name', required: true }}
            />
          </div>

          <div className="form-row">
            <FormField
              label="Date de naissance"
              type="date"
              error={fieldErrors.date_naissance}
              hint={
                age === null
                  ? 'Votre âge sera calculé automatiquement.'
                  : ageWarning
                    ? `Âge calculé : ${age} ans — le programme s'adresse aux 18-30 ans.`
                    : `Âge calculé : ${age} ans`
              }
              inputProps={{ value: form.date_naissance, onChange: set('date_naissance'), required: true }}
            />
            <div className="form-group">
              <label className="label" htmlFor="sexe">Sexe</label>
              <select
                id="sexe"
                className={`select${fieldErrors.sexe ? ' is-error' : ''}`}
                value={form.sexe}
                onChange={set('sexe')}
                required
                aria-invalid={fieldErrors.sexe ? 'true' : undefined}
              >
                <option value="">Sélectionner</option>
                <option value="F">Féminin</option>
                <option value="H">Masculin</option>
              </select>
              {fieldErrors.sexe ? (
                <p className="input-error">
                  <i className="fa-solid fa-circle-exclamation" aria-hidden="true" /> {fieldErrors.sexe[0]}
                </p>
              ) : null}
            </div>
          </div>

          <div className="form-row">
            <FormField
              label="Numéro CNI / récépissé"
              error={fieldErrors.cni}
              inputProps={{ value: form.cni, onChange: set('cni'), placeholder: 'CI…', required: true }}
            />
            <FormField
              label="Ville de résidence"
              error={fieldErrors.ville_residence}
              hint="Ex. Abidjan - Cocody, Bouaké, San-Pédro…"
              inputProps={{
                value: form.ville_residence,
                onChange: set('ville_residence'),
                autoComplete: 'address-level2',
                required: true,
              }}
            />
          </div>

          <div className="form-row">
            <FormField
              label="Téléphone"
              type="tel"
              error={fieldErrors.telephone}
              hint="Indicatif 225 suivi de votre numéro — ex. 2250717948700."
              inputProps={{ value: form.telephone, onChange: set('telephone'), inputMode: 'tel', autoComplete: 'tel', required: true }}
            />
            <FormField
              label="Adresse e-mail"
              type="email"
              error={fieldErrors.email}
              inputProps={{ value: form.email, onChange: set('email'), autoComplete: 'email', required: true }}
            />
          </div>

          <div className="form-row">
            <FormField
              label="Mot de passe"
              type="password"
              error={fieldErrors.password}
              hint="Au moins 10 caractères, avec majuscules, minuscules et chiffres."
              inputProps={{ value: form.password, onChange: set('password'), autoComplete: 'new-password', required: true }}
            />
            <FormField
              label="Confirmer le mot de passe"
              type="password"
              error={fieldErrors.password_confirmation}
              inputProps={{
                value: form.password_confirmation,
                onChange: set('password_confirmation'),
                autoComplete: 'new-password',
                required: true,
              }}
            />
          </div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)', margin: 'var(--space-4) 0 var(--space-6)' }}>
            <Checkbox
              label="Je déclare résider en Côte d'Ivoire."
              checked={residenceCi}
              onChange={setResidenceCi}
              error={fieldErrors.residence_ci}
              required
            />
            <Checkbox
              label="J'accepte les conditions d'utilisation et la politique de confidentialité du projet CASA."
              checked={cgu}
              onChange={setCgu}
              error={fieldErrors.cgu}
              required
            />
          </div>

          <button
            type="submit"
            className={`btn btn-primary btn-lg btn-block${submitting ? ' is-loading' : ''}`}
            disabled={submitting}
          >
            Créer mon compte <i className="fa-solid fa-arrow-right" aria-hidden="true" />
          </button>
        </form>

        <p className="text-center caption" style={{ marginTop: 'var(--space-6)' }}>
          Vous avez déjà un compte ?{' '}
          <Link to={paths.login} className="text-primary-brand fw-semibold">Se connecter</Link>
        </p>
      </main>
      <PublicFooter />
    </>
  )
}
