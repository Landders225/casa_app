import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Alert } from '../../components/ui/Alert.jsx'
import { FormField } from '../../components/ui/FormField.jsx'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'
import { paths } from '../../routing/routes.js'
import './LoginPage.css'

/**
 * « Mot de passe oublié » (Lot 13, ADR-32) — `POST /api/mot-de-passe/oubli`.
 *
 * ANTI-ÉNUMÉRATION : le message affiché après soumission est TOUJOURS le même,
 * quel que soit le sort réel de la requête (compte existant ou non — le
 * serveur ne le distingue jamais, cf. ADR-32). Cet écran n'a donc RIEN à
 * distinguer non plus : un seul état de succès, point.
 */
export function MotDePasseOublie() {
  const [email, setEmail] = useState('')
  const [envoye, setEnvoye] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(null)

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (submitting) return
    setSubmitting(true)
    setError(null)

    try {
      await apiClient.post('/mot-de-passe/oubli', { email })
      setEnvoye(true) // même écran de succès quoi qu'il arrive côté serveur
    } catch (err) {
      if (err instanceof ApiError && err.kind === 'validation') {
        setError(err.errors?.email?.[0] || err.message)
      } else if (err instanceof ApiError && err.kind === 'rate_limited') {
        setError('Trop de tentatives. Réessayez dans une minute.')
      } else {
        setError('Impossible de contacter le serveur. Vérifiez votre connexion.')
      }
    } finally {
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
        <div>
          <h2 style={{ color: '#fff', maxWidth: 380 }}>Récupérez l'accès à votre compte.</h2>
          <p style={{ color: 'var(--casa-primary-100)', marginTop: 'var(--space-4)', maxWidth: 380 }}>
            Indiquez votre adresse e-mail : si un compte y est associé, vous recevrez un lien pour
            choisir un nouveau mot de passe.
          </p>
        </div>
      </aside>

      <div className="auth-form-wrap">
        <div className="auth-form">
          <span className="eyebrow">
            <i className="fa-solid fa-key" aria-hidden="true" /> Mot de passe oublié
          </span>
          <h1 className="h1" style={{ marginTop: 'var(--space-3)' }}>Réinitialiser mon mot de passe</h1>

          {envoye ? (
            <>
              <div style={{ margin: 'var(--space-6) 0' }}>
                <Alert variant="success" title="Vérifiez votre boîte de réception">
                  Si un compte existe pour cette adresse, un lien de réinitialisation vient d'être
                  envoyé. Le lien est valable 60 minutes et utilisable une seule fois.
                </Alert>
              </div>
              <Link to={paths.login} className="btn btn-outline btn-block">
                Retour à la connexion
              </Link>
            </>
          ) : (
            <>
              <p className="text-muted" style={{ marginTop: 'var(--space-2)', marginBottom: 'var(--space-6)' }}>
                Saisissez l'adresse e-mail de votre compte CASA.
              </p>

              {error ? (
                <div style={{ marginBottom: 'var(--space-4)' }}>
                  <Alert variant="danger">{error}</Alert>
                </div>
              ) : null}

              <form onSubmit={handleSubmit} noValidate>
                <FormField
                  label="Adresse e-mail"
                  type="email"
                  icon="fa-envelope"
                  inputProps={{
                    value: email,
                    onChange: (e) => setEmail(e.target.value),
                    placeholder: 'vous@exemple.ci',
                    autoComplete: 'email',
                    required: true,
                  }}
                />
                <button
                  type="submit"
                  className={`btn btn-primary btn-lg btn-block${submitting ? ' is-loading' : ''}`}
                  disabled={submitting}
                >
                  Envoyer le lien <i className="fa-solid fa-paper-plane" aria-hidden="true" />
                </button>
              </form>

              <p className="caption" style={{ marginTop: 'var(--space-5)', textAlign: 'center' }}>
                <Link to={paths.login}>Retour à la connexion</Link>
              </p>
            </>
          )}
        </div>
      </div>
    </div>
  )
}
