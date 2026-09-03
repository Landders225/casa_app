import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from './auth/useAuth.js'
import { FullPageSpinner } from './components/ui/Spinner.jsx'
import { LoginPage } from './pages/auth/LoginPage.jsx'
import { NotFoundPage } from './pages/NotFoundPage.jsx'
import { HomePage } from './pages/public/HomePage.jsx'
import { InscriptionPage } from './pages/public/InscriptionPage.jsx'
import { CandidatDashboard } from './pages/candidat/CandidatDashboard.jsx'
import { AdminPlaceholder, EvaluateurPlaceholder } from './pages/SpacePlaceholder.jsx'
import { ProtectedRoute } from './routing/ProtectedRoute.jsx'
import { RedirectIfAuthed } from './routing/RedirectIfAuthed.jsx'
import { paths, roleHome } from './routing/routes.js'

/**
 * Racine « / » : accueil public pour les visiteurs, redirection vers l'espace
 * du rôle pour un utilisateur connecté.
 */
function Home() {
  const { status, role } = useAuth()
  if (status === 'loading') return <FullPageSpinner />
  if (status === 'authenticated') return <Navigate to={roleHome(role)} replace />
  return <HomePage />
}

export default function App() {
  return (
    <Routes>
      <Route path={paths.home} element={<Home />} />

      <Route
        path={paths.login}
        element={
          <RedirectIfAuthed>
            <LoginPage />
          </RedirectIfAuthed>
        }
      />
      <Route
        path={paths.inscription}
        element={
          <RedirectIfAuthed>
            <InscriptionPage />
          </RedirectIfAuthed>
        }
      />

      <Route
        path={`${paths.candidat}/*`}
        element={
          <ProtectedRoute roles={['candidat']}>
            <CandidatDashboard />
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
