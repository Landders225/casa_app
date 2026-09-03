import { Link } from 'react-router-dom'
import { useAuth } from '../auth/useAuth.js'
import { paths, roleHome } from '../routing/routes.js'

export function NotFoundPage() {
  const { status, role } = useAuth()
  const target = status === 'authenticated' ? roleHome(role) : paths.login

  return (
    <div className="app-boot">
      <div className="empty-state">
        <div className="empty-icon">
          <i className="fa-solid fa-compass" aria-hidden="true" />
        </div>
        <h3>Page introuvable</h3>
        <p>La page demandée n'existe pas ou a été déplacée.</p>
        <Link className="btn btn-primary" to={target}>
          Retour à l'accueil
        </Link>
      </div>
    </div>
  )
}
