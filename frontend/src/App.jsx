import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from './auth/useAuth.js'
import { FullPageSpinner } from './components/ui/Spinner.jsx'
import { LoginPage } from './pages/auth/LoginPage.jsx'
import { NotFoundPage } from './pages/NotFoundPage.jsx'
import {
  AdminPlaceholder,
  CandidatPlaceholder,
  EvaluateurPlaceholder,
} from './pages/SpacePlaceholder.jsx'
import { ProtectedRoute } from './routing/ProtectedRoute.jsx'
import { RedirectIfAuthed } from './routing/RedirectIfAuthed.jsx'
import { paths, roleHome } from './routing/routes.js'

/** Racine « / » : renvoie vers l'espace du rôle, ou vers la connexion. */
function HomeRedirect() {
  const { status, role } = useAuth()
  if (status === 'loading') return <FullPageSpinner />
  return <Navigate to={status === 'authenticated' ? roleHome(role) : paths.login} replace />
}

export default function App() {
  return (
    <Routes>
      <Route path={paths.home} element={<HomeRedirect />} />

      <Route
        path={paths.login}
        element={
          <RedirectIfAuthed>
            <LoginPage />
          </RedirectIfAuthed>
        }
      />

      <Route
        path={`${paths.candidat}/*`}
        element={
          <ProtectedRoute roles={['candidat']}>
            <CandidatPlaceholder />
          </ProtectedRoute>
        }
      />
      <Route
        path={`${paths.evaluateur}/*`}
        element={
          <ProtectedRoute roles={['evaluateur']}>
            <EvaluateurPlaceholder />
          </ProtectedRoute>
        }
      />
      <Route
        path={`${paths.admin}/*`}
        element={
          <ProtectedRoute roles={['administrateur']}>
            <AdminPlaceholder />
          </ProtectedRoute>
        }
      />

      <Route path="*" element={<NotFoundPage />} />
    </Routes>
  )
}
