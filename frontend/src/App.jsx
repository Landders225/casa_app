import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from './auth/useAuth.js'
import { FullPageSpinner } from './components/ui/Spinner.jsx'
import { LoginPage } from './pages/auth/LoginPage.jsx'
import { MotDePasseOublie } from './pages/auth/MotDePasseOublie.jsx'
import { NouveauMotDePasse } from './pages/auth/NouveauMotDePasse.jsx'
import { NotFoundPage } from './pages/NotFoundPage.jsx'
import { HomePage } from './pages/public/HomePage.jsx'
import { InscriptionPage } from './pages/public/InscriptionPage.jsx'
import { CandidatDashboard } from './pages/candidat/CandidatDashboard.jsx'
import { CandidatureWizard } from './pages/candidat/CandidatureWizard.jsx'
import { Documents } from './pages/candidat/Documents.jsx'
import { MaCandidature } from './pages/candidat/MaCandidature.jsx'
import { Notifications } from './pages/candidat/Notifications.jsx'
import { Profil } from './pages/candidat/Profil.jsx'
import { DossiersList } from './pages/evaluateur/DossiersList.jsx'
import { Entretien } from './pages/evaluateur/Entretien.jsx'
import { EvaluateurDashboard } from './pages/evaluateur/EvaluateurDashboard.jsx'
import { EvaluationDossier } from './pages/evaluateur/EvaluationDossier.jsx'
import { FicheCandidat } from './pages/evaluateur/FicheCandidat.jsx'
import { AdminDashboard } from './pages/admin/AdminDashboard.jsx'
import { Audit } from './pages/admin/Audit.jsx'
import { Campagnes } from './pages/admin/Campagnes.jsx'
import { CandidaturesSupervision } from './pages/admin/CandidaturesSupervision.jsx'
import { Classement } from './pages/admin/Classement.jsx'
import { Equipe } from './pages/admin/Equipe.jsx'
import { Filieres } from './pages/admin/Filieres.jsx'
import { Rapports } from './pages/admin/Rapports.jsx'
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
      <Route path={paths.motDePasseOublie} element={<MotDePasseOublie />} />
      <Route path={paths.motDePasseNouveau} element={<NouveauMotDePasse />} />

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
        path={paths.candidatProfil}
        element={
          <ProtectedRoute roles={['candidat']}>
            <Profil />
          </ProtectedRoute>
        }
      />
      <Route
        path={paths.candidatDocuments}
        element={
          <ProtectedRoute roles={['candidat']}>
            <Documents />
          </ProtectedRoute>
        }
      />
      <Route
        path={paths.candidatNotifications}
        element={
          <ProtectedRoute roles={['candidat']}>
            <Notifications />
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
        path="/evaluateur/candidatures/:id/evaluation"
        element={
          <ProtectedRoute roles={['evaluateur', 'administrateur']}>
            <EvaluationDossier />
          </ProtectedRoute>
        }
      />
      <Route
        path="/evaluateur/candidatures/:id/entretien"
        element={
          <ProtectedRoute roles={['evaluateur', 'administrateur']}>
            <Entretien />
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
        path={paths.admin}
        element={
          <ProtectedRoute roles={['administrateur']}>
            <AdminDashboard />
          </ProtectedRoute>
        }
      />
      <Route
        path={paths.adminCandidatures}
        element={
          <ProtectedRoute roles={['administrateur']}>
            <CandidaturesSupervision />
          </ProtectedRoute>
        }
      />
      <Route
        path={paths.adminFilieres}
        element={
          <ProtectedRoute roles={['administrateur']}>
            <Filieres />
          </ProtectedRoute>
        }
      />
      <Route
        path={paths.adminCampagnes}
        element={
          <ProtectedRoute roles={['administrateur']}>
            <Campagnes />
          </ProtectedRoute>
        }
      />
      <Route
        path={paths.adminEquipe}
        element={
          <ProtectedRoute roles={['administrateur']}>
            <Equipe />
          </ProtectedRoute>
        }
      />
      <Route
        path={paths.adminRapports}
        element={
          <ProtectedRoute roles={['administrateur']}>
            <Rapports />
          </ProtectedRoute>
        }
      />
      <Route
        path={paths.adminAudit}
        element={
          <ProtectedRoute roles={['administrateur']}>
            <Audit />
          </ProtectedRoute>
        }
      />
      <Route
        path={paths.adminClassement}
        element={
          <ProtectedRoute roles={['administrateur']}>
            <Classement />
          </ProtectedRoute>
        }
      />
      <Route
        path="/admin/classement/:campagneId"
        element={
          <ProtectedRoute roles={['administrateur']}>
            <Classement />
          </ProtectedRoute>
        }
      />

      <Route path="*" element={<NotFoundPage />} />
    </Routes>
  )
}
