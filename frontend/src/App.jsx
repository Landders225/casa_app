import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from './auth/useAuth.js'
import { FullPageSpinner } from './components/ui/Spinner.jsx'
import { LoginPage } from './pages/auth/LoginPage.jsx'
import { NotFoundPage } from './pages/NotFoundPage.jsx'
import { HomePage } from './pages/public/HomePage.jsx'
import { InscriptionPage } from './pages/public/InscriptionPage.jsx'
import { CandidatDashboard } from './pages/candidat/CandidatDashboard.jsx'
import { CandidatureWizard } from './pages/candidat/CandidatureWizard.jsx'
import { MaCandidature } from './pages/candidat/MaCandidature.jsx'
import { DossiersList } from './pages/evaluateur/DossiersList.jsx'
import { EvaluateurDashboard } from './pages/evaluateur/EvaluateurDashboard.jsx'
import { FicheCandidat } from './pages/evaluateur/FicheCandidat.jsx'
import { AdminPlaceholder } from './pages/SpacePlaceholder.jsx'
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
        path={paths.candidatureWizard}
        element={
          <ProtectedRoute roles={['candidat']}>
            <CandidatureWizard />
          </ProtectedRoute>
        }
      />
      <Route
        path={paths.maCandidature}
        element={
          <ProtectedRoute roles={['candidat']}>
            <MaCandidature />
          </ProtectedRoute>
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
        path={paths.evaluateurDossiers}
        element={
          <ProtectedRoute roles={['evaluateur', 'administrateur']}>
            <DossiersList />
          </ProtectedRoute>
        }
      />
      <Route
        path="/evaluateur/candidatures/:id"
        element={
          <ProtectedRoute roles={['evaluateur', 'administrateur']}>
            <FicheCandidat />
          </ProtectedRoute>
        }
      />
      <Route
        path={`${paths.evaluateur}/*`}
        element={
          <ProtectedRoute roles={['evaluateur', 'administrateur']}>
            <EvaluateurDashboard />
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
