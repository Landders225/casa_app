import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/useAuth.js'
import { FullPageSpinner } from '../components/ui/Spinner.jsx'
import { paths, roleHome } from './routes.js'

/**
 * Garde de route par rôle (Lot 8a — strict, cf. routes.js).
 *
 *  - status 'loading'  -> écran de chargement (on attend GET /api/me) ;
 *  - 'guest'           -> redirection vers /connexion (mémorise l'URL demandée) ;
 *  - rôle non autorisé -> redirection vers SON espace (roleHome), pas une 403 ;
 *  - sinon             -> rend le contenu protégé.
 */
export function ProtectedRoute({ roles, children }) {
  const { status, role } = useAuth()
  const location = useLocation()

  if (status === 'loading') return <FullPageSpinner />

  if (status !== 'authenticated') {
    return <Navigate to={paths.login} replace state={{ from: location.pathname }} />
  }

  if (roles && !roles.includes(role)) {
    return <Navigate to={roleHome(role)} replace />
  }

  return children
}
