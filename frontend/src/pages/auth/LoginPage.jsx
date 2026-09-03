import { useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth.js'
import { Alert } from '../../components/ui/Alert.jsx'
import { FormField } from '../../components/ui/FormField.jsx'
import { ApiError } from '../../lib/ApiError.js'
import { roleHome } from '../../routing/routes.js'
import './LoginPage.css'

const DEMO_ACCOUNTS = [
  { key: 'candidat', label: 'Candidat', email: 'candidat@casa-demo.ci' },
  { key: 'evaluateur', label: 'Évaluateur', email: 'evaluateur@casa-demo.ci' },
  { key: 'admin', label: 'Administrateur', email: 'admin@casa-demo.ci' },
]
const DEMO_PASSWORD = 'Demo2026!'

/**
 * Écran de connexion transverse. Consomme POST /api/login via AuthContext
 * (cycle Sanctum géré par apiClient), affiche le message d'erreur GÉNÉRIQUE du
 * backend, puis redirige vers l'espace du rôle (ou l'URL initialement demandée).
 */
export function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const from = location.state?.from

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(null)

  const fillDemo = (account) => {
    setEmail(account.email)
    setPassword(DEMO_PASSWORD)
    setError(null)
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (submitting) return
    setSubmitting(true)
    setError(null)

    try {
      const user = await login(email, password)
      const target = from && from !== '/connexion' ? from : roleHome(user.role)
      navigate(target, { replace: true })
    } catch (err) {
      if (err instanceof ApiError) {
        if (err.kind === 'validation') {
          // Échec de connexion : le backend renvoie un message unique et
          // GÉNÉRIQUE (« E-mail ou mot de passe incorrect. ») dans errors.email —
          // on l'affiche comme message global, pas comme erreur de champ.
          setError(err.errors?.email?.[0] || err.message)
        } else if (err.kind === 'rate_limited') {
          setError('Trop de tentatives de connexion. Réessayez dans une minute.')
        } else if (err.kind === 'network') {
          setError('Impossible de contacter le serveur. Vérifiez votre connexion.')
        } else {
          setError('La connexion a échoué. Réessayez.')
        }
      } else {
        setError('La connexion a échoué. Réessayez.')
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
        <div>
          <h2 style={{ color: '#fff', maxWidth: 380 }}>Bienvenue sur la plateforme de sélection CASA.</h2>
          <p style={{ color: 'var(--casa-primary-100)', marginTop: 'var(--space-4)', maxWidth: 380 }}>
            Suivez votre candidature, évaluez des dossiers ou pilotez le programme — tout en un seul endroit.
          </p>
        </div>
        <div className="flex gap-3 items-center">
          <span className="avatar" style={{ background: 'rgba(255,255,255,0.15)' }}>
            <i className="fa-solid fa-users" aria-hidden="true" />
          </span>
          <div>
            <div className="fw-semibold">240 jeunes accompagnés</div>
            <div className="caption" style={{ color: 'var(--casa-primary-200)' }}>2 cohortes · 5 filières CQP</div>
          </div>
        </div>
      </aside>

      <div className="auth-form-wrap">
        <div className="auth-form">
          <span className="eyebrow">
            <i className="fa-solid fa-lock" aria-hidden="true" /> Connexion
          </span>
          <h1 className="h1" style={{ marginTop: 'var(--space-3)' }}>Accéder à mon espace</h1>
          <p className="text-muted" style={{ marginTop: 'var(--space-2)', marginBottom: 'var(--space-6)' }}>
            Connectez-vous pour continuer votre parcours CASA.
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
            <FormField
              label="Mot de passe"
              type="password"
              icon="fa-key"
              inputProps={{
                value: password,
                onChange: (e) => setPassword(e.target.value),
                placeholder: '••••••••',
                autoComplete: 'current-password',
                required: true,
              }}
            />
            <button
              type="submit"
              className={`btn btn-primary btn-lg btn-block${submitting ? ' is-loading' : ''}`}
              disabled={submitting}
            >
              Se connecter <i className="fa-solid fa-arrow-right" aria-hidden="true" />
            </button>
          </form>

          <div className="demo-accounts">
            <div className="eyebrow" style={{ marginBottom: 'var(--space-3)' }}>
              <i className="fa-solid fa-flask" aria-hidden="true" /> Comptes de démonstration
            </div>
            {DEMO_ACCOUNTS.map((account) => (
              <div className="demo-row" key={account.key}>
                <span>
                  <strong>{account.label}</strong>
                  <br />
                  {account.email}
                </span>
                <button type="button" className="btn btn-sm btn-outline" onClick={() => fillDemo(account)}>
                  Utiliser
                </button>
              </div>
            ))}
            <p className="caption" style={{ marginTop: 'var(--space-3)' }}>
              Mot de passe pour les 3 comptes : <code>{DEMO_PASSWORD}</code>
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
