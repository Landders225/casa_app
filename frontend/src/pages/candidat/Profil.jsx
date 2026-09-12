import { useEffect, useState } from 'react'
import { AppShell } from '../../components/layout/AppShell.jsx'
import { Alert } from '../../components/ui/Alert.jsx'
import { FormField } from '../../components/ui/FormField.jsx'
import { FullPageSpinner } from '../../components/ui/Spinner.jsx'
import { useAuth } from '../../auth/useAuth.js'
import { ApiError } from '../../lib/ApiError.js'
import { ChangementMotDePasseCard } from '../../components/account/ChangementMotDePasseCard.jsx'
import { useMaCandidature } from './useMaCandidature.js'
import { useProfil } from './useProfil.js'

/**
 * Mon profil (Lot 13) — réconcilié sur le BACKEND (source d'autorité), pas sur
 * la maquette : `PATCH /api/candidat/profil` (Lot 7, ADR-16) autorise prénom,
 * nom, sexe, date de naissance, CNI, téléphone, ville — PAS l'e-mail (identifiant
 * de connexion, flux dédié) ni la résidence CI (fixée à l'inscription). La
 * nationalité et le diplôme n'existent pas côté candidat (ADR-07) : ils ne sont
 * même pas proposés ici.
 *
 * `date_naissance` reste éditable : une correction qui sortirait de la tranche
 * 18-30 ans est refusée par le serveur (422, message affiché tel quel).
 *
 * Verrouillage identité post-soumission (Lot 15b) : si la candidature est déjà
 * SOUMISE (`date_soumission` non nul, Lot 8b-3), les 5 champs d'identité
 * (prénom/nom/sexe/date de naissance/CNI) sont désactivés visuellement ET
 * exclus du payload envoyé au serveur — le serveur les refuse de toute façon
 * (`prohibited`, `MettreAJourProfilRequest`), mais les envoyer quand même
 * casserait la mise à jour des 2 champs qui restent éditables (téléphone,
 * ville). `telephone` / `ville_residence` ne sont jamais concernés.
 */
function calculerAge(dateNaissance) {
  if (!dateNaissance) return null
  const naissance = new Date(dateNaissance)
  if (Number.isNaN(naissance.getTime())) return null
  const aujourdhui = new Date()
  let age = aujourdhui.getFullYear() - naissance.getFullYear()
  const pasEncoreAnniversaire =
    aujourdhui.getMonth() < naissance.getMonth() ||
    (aujourdhui.getMonth() === naissance.getMonth() && aujourdhui.getDate() < naissance.getDate())
  if (pasEncoreAnniversaire) age -= 1
  return age
}

const CHAMPS_VERROUILLABLES = ['prenom', 'nom', 'sexe', 'date_naissance', 'cni']
const CHAMPS_TOUJOURS_EDITABLES = ['telephone', 'ville_residence']

export function Profil() {
  const { user } = useAuth()
  const { status, profil, saving, enregistrer } = useProfil()
  const candidature = useMaCandidature()

  const [form, setForm] = useState(null)
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)
  const [succes, setSucces] = useState(false)

  useEffect(() => {
    if (profil) {
      // oxlint-disable-next-line react/set-state-in-effect -- initialise le brouillon local depuis la réponse serveur, une fois chargée
      setForm({
        prenom: profil.prenom, nom: profil.nom, sexe: profil.sexe,
        date_naissance: profil.date_naissance, cni: profil.cni,
        telephone: profil.telephone, ville_residence: profil.ville_residence,
      })
    }
  }, [profil])

  if (status === 'loading' || !form) return <FullPageSpinner />

  if (status === 'error') {
    return (
      <AppShell title="Mon profil">
        <Alert variant="warning">Votre profil n'a pas pu être chargé. Réessayez plus tard.</Alert>
      </AppShell>
    )
  }

  const dejaSoumis = candidature.status === 'ready' && Boolean(candidature.dateSoumission)

  const set = (champ) => (e) => setForm((f) => ({ ...f, [champ]: e.target.value }))

  const submit = async (e) => {
    e.preventDefault()
    setErrors({})
    setMessage(null)
    setSucces(false)
    try {
      // Si le dossier est déjà soumis, les 5 champs d'identité sont EXCLUS du
      // payload (pas seulement désactivés) : le serveur les refuse (prohibited)
      // dès qu'ils sont présents, même à valeur inchangée — les envoyer quand
      // même empêcherait de sauvegarder un simple changement de téléphone/ville.
      const champsAEnvoyer = dejaSoumis
        ? CHAMPS_TOUJOURS_EDITABLES
        : [...CHAMPS_VERROUILLABLES, ...CHAMPS_TOUJOURS_EDITABLES]
      const patch = Object.fromEntries(champsAEnvoyer.map((c) => [c, form[c]]))
      await enregistrer(patch)
      setSucces(true)
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        setErrors(err.errors ?? {})
        setMessage(err.errors && Object.keys(err.errors).length ? null : err.message)
      } else {
        setMessage(err instanceof ApiError ? err.message : 'La mise à jour a échoué.')
      }
    }
  }

  const age = calculerAge(form.date_naissance)

  return (
    <AppShell title="Mon profil">
      <div className="page-head">
        <div>
          <h2>Mon profil</h2>
          <p className="text-muted">Vos informations personnelles transmises lors de l'inscription.</p>
        </div>
      </div>

      {dejaSoumis ? (
        <div style={{ marginBottom: 'var(--space-5)' }}>
          <Alert variant="info">
            Votre dossier est déjà transmis. Les champs d'identité (prénom, nom, sexe, date de
            naissance, numéro CNI) sont verrouillés — contactez l'équipe si une correction doit y
            être apportée. Téléphone et ville de résidence restent modifiables.
          </Alert>
        </div>
      ) : null}

      <div className="dashboard-layout">
        <div className="card">
          <div className="card-header">
            <h3>Coordonnées</h3>
          </div>

          {succes ? (
            <div style={{ marginBottom: 'var(--space-4)' }}>
              <Alert variant="success">Votre profil a été mis à jour.</Alert>
            </div>
          ) : null}
          {message ? (
            <div style={{ marginBottom: 'var(--space-4)' }}>
              <Alert variant="danger">{message}</Alert>
            </div>
          ) : null}

          <form onSubmit={submit} noValidate>
            <div className="form-row">
              <FormField label="Prénom" error={errors.prenom} inputProps={{ value: form.prenom, onChange: set('prenom'), required: true, disabled: dejaSoumis }} />
              <FormField label="Nom" error={errors.nom} inputProps={{ value: form.nom, onChange: set('nom'), required: true, disabled: dejaSoumis }} />
            </div>

            <div className="form-row">
              <FormField
                label="Date de naissance"
                type="date"
                error={errors.date_naissance}
                hint={age === null ? undefined : `Âge calculé : ${age} ans`}
                inputProps={{ value: form.date_naissance, onChange: set('date_naissance'), required: true, disabled: dejaSoumis }}
              />
              <div className="form-group">
                <label className="label" htmlFor="profil-sexe">Sexe</label>
                <select
                  id="profil-sexe"
                  className={`select${errors.sexe ? ' is-error' : ''}`}
                  value={form.sexe}
                  onChange={set('sexe')}
                  required
                  disabled={dejaSoumis}
                >
                  <option value="F">Féminin</option>
                  <option value="H">Masculin</option>
                </select>
                {errors.sexe ? (
                  <p className="input-error">
                    <i className="fa-solid fa-circle-exclamation" aria-hidden="true" /> {errors.sexe[0]}
                  </p>
                ) : null}
              </div>
            </div>

            <div className="form-row">
              <FormField label="Numéro CNI / récépissé" error={errors.cni} inputProps={{ value: form.cni, onChange: set('cni'), required: true, disabled: dejaSoumis }} />
              <FormField
                label="Téléphone"
                type="tel"
                error={errors.telephone}
                inputProps={{ value: form.telephone, onChange: set('telephone'), autoComplete: 'tel', required: true }}
              />
            </div>

            <FormField
              label="Ville de résidence"
              error={errors.ville_residence}
              inputProps={{ value: form.ville_residence, onChange: set('ville_residence'), required: true }}
            />

            <button type="submit" className={`btn btn-primary btn-block${saving ? ' is-loading' : ''}`} disabled={saving}>
              Enregistrer les modifications
            </button>
          </form>
        </div>

        <div>
          <div className="card" style={{ marginBottom: 'var(--space-6)' }}>
            <div className="card-header">
              <h3>Identifiant de connexion</h3>
              <span className="badge badge-neutral">
                <i className="fa-solid fa-lock" aria-hidden="true" /> Non modifiable
              </span>
            </div>
            <div className="form-group">
              <label className="label" htmlFor="profil-email">E-mail</label>
              <input id="profil-email" className="input" value={user?.email ?? ''} readOnly disabled />
            </div>
            <p className="caption">
              Pour changer d'adresse e-mail, contactez l'équipe projet.
            </p>
            <hr className="divider" style={{ margin: 'var(--space-5) 0' }} />
            <div className="form-group" style={{ marginBottom: 0 }}>
              <label className="label" htmlFor="profil-residence-ci">Résidence en Côte d'Ivoire</label>
              <input id="profil-residence-ci" className="input" value={profil.residence_ci ? 'Oui' : 'Non'} readOnly disabled />
            </div>
          </div>

          <ChangementMotDePasseCard endpoint="/candidat/mot-de-passe" />
        </div>
      </div>
    </AppShell>
  )
}
