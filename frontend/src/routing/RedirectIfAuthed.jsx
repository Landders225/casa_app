import { Navigate } from 'react-router-dom'
import { useAuth } from '../auth/useAuth.js'
import { FullPageSpinner } from '../components/ui/Spinner.jsx'
import { roleHome } from './routes.js'

/**
 * Pour les pages publiques d'authentification (/connexion) : un utilisateur déjà
 * connecté est renvoyé vers son espace plutôt que de revoir le formulaire.
 */
export function RedirectIfAuthed({ children }) {
  const { status, role } = useAuth()

  if (status === 'loading') return <FullPageSpinner />
  if (status === 'authenticated') return <Navigate to={roleHome(role)} replace />

  return children
}
