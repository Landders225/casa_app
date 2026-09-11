import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { Alert } from '../../components/ui/Alert.jsx'
import { FormField } from '../../components/ui/FormField.jsx'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'
import { paths } from '../../routing/routes.js'
import './LoginPage.css'

/**
 * Saisie du nouveau mot de passe (Lot 13, ADR-32) — page ouverte depuis le lien
 * reçu par e-mail. `token`/`email` viennent de la query string
 * (`?token=…&email=…` — cf. `ReinitialisationMotDePasse::lien()` côté backend ;
 * nginx exclut CETTE route de ses logs d'accès pour ne pas y archiver le
 * token, cf. `docker/nginx/*.conf`).
 *
 * `token`/`email` invalides, déjà utilisés ou expirés reçoivent le MÊME
 * message générique (le backend ne distingue jamais « mauvais token » de
 * « e-mail inconnu », ADR-32) — cet écran se contente de l'afficher tel quel.
 */
export function NouveauMotDePasse() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const token = searchParams.get('token') ?? ''
  const email = searchParams.get('email') ?? ''

  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(null)

  const lienIncomplet = !token || !email

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (submitting) return
    setSubmitting(true)
    setError(null)

    try {
      await apiClient.post('/mot-de-passe/reinitialiser', {
        token, email, password, password_confirmation: passwordConfirmation,
      })
      navigate(paths.login, { replace: true, state: { motDePasseReinitialise: true } })
    } catch (err) {
      if (err instanceof ApiError && err.kind === 'validation') {
        setError(err.errors?.password?.[0] || err.message)
      } else if (err instanceof ApiError && err.kind === 'rate_limited') {
        setError('Trop de tentatives. Réessayez dans une minute.')
      } else {
        setError('Impossible de contacter le serveur. Vérifiez votre connexion.')
      }
      setSubmitting(false)
    }
  }

  return (
    <div className="auth-shell">
      <aside className="auth-side">
        <span
          className="blob"
          style={{ width: 340, height: 340, background: 'var(--casa-accent-500)', top: -100, right: -80, opacity: 0.25 }}
        />
        <span className="brand">
          <span className="brand-mark">
            <i className="fa-solid fa-seedling" aria-hidden="true" />
          </span>{' '}
          CASA
        </span>
      </aside>

      <div className="auth-form-wrap">
        <div className="auth-form">
          <span className="eyebrow">
            <i className="fa-solid fa-key" aria-hidden="true" /> Nouveau mot de passe
          </span>
          <h1 className="h1" style={{ marginTop: 'var(--space-3)' }}>Choisir un nouveau mot de passe</h1>

          {lienIncomplet ? (
            <div style={{ margin: 'var(--space-6) 0' }}>
              <Alert variant="warning">
                Ce lien de réinitialisation est incomplet. Redemandez un lien depuis la page
                « Mot de passe oublié ».
              </Alert>
              <Link to={paths.motDePasseOublie} className="btn btn-outline btn-block" style={{ marginTop: 'var(--space-4)' }}>
                Redemander un lien
              </Link>
            </div>
          ) : (
            <>
              <p className="text-muted" style={{ marginTop: 'var(--space-2)', marginBottom: 'var(--space-6)' }}>
                Pour {email}.
              </p>

              {error ? (
                <div style={{ marginBottom: 'var(--space-4)' }}>
                  <Alert variant="danger">{error}</Alert>
                  {error.startsWith('Ce lien') ? (
                    <p className="caption" style={{ marginTop: 'var(--space-2)' }}>
                      <Link to={paths.motDePasseOublie}>Redemander un lien</Link>
                    </p>
                  ) : null}
                </div>
              ) : null}

              <form onSubmit={handleSubmit} noValidate>
                <FormField
                  label="Nouveau mot de passe"
                  type="password"
                  icon="fa-key"
                  hint="Au moins 10 caractères, avec majuscule, minuscule et chiffre."
                  inputProps={{
                    value: password,
                    onChange: (e) => setPassword(e.target.value),
                    autoComplete: 'new-password',
                    required: true,
                  }}
                />
                <FormField
                  label="Confirmer le nouveau mot de passe"
                  type="password"
                  icon="fa-key"
                  inputProps={{
                    value: passwordConfirmation,
                    onChange: (e) => setPasswordConfirmation(e.target.value),
                    autoComplete: 'new-password',
                    required: true,
                  }}
                />
                <button
                  type="submit"
                  className={`btn btn-primary btn-lg btn-block${submitting ? ' is-loading' : ''}`}
                  disabled={submitting}
                >
                  Réinitialiser mon mot de passe <i className="fa-solid fa-check" aria-hidden="true" />
                </button>
              </form>
            </>
          )}

          <p className="caption" style={{ marginTop: 'var(--space-5)', textAlign: 'center' }}>
            <Link to={paths.login}>Retour à la connexion</Link>
          </p>
        </div>
      </div>
    </div>
  )
}
