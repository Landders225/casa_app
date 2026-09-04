import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../auth/useAuth.js'
import { ProtectedRoute } from '../ProtectedRoute.jsx'

vi.mock('../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

function renderAt(path, roles) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route
          path="/candidat/*"
          element={
            <ProtectedRoute roles={roles}>
              <div>contenu candidat</div>
            </ProtectedRoute>
          }
        />
        <Route path="/connexion" element={<div>écran de connexion</div>} />
        <Route path="/evaluateur" element={<div>espace évaluateur</div>} />
        <Route path="/admin" element={<div>espace admin</div>} />
      </Routes>
    </MemoryRouter>,
  )
}

/** Mêmes routes que App.jsx pour /evaluateur/* : roles ['evaluateur','administrateur']. */
function renderAtEvaluateur(path) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route
          path="/evaluateur/*"
          element={
            <ProtectedRoute roles={['evaluateur', 'administrateur']}>
              <div>contenu évaluateur</div>
            </ProtectedRoute>
          }
        />
        <Route
          path="/admin/*"
          element={
            <ProtectedRoute roles={['administrateur']}>
              <div>contenu admin</div>
            </ProtectedRoute>
          }
        />
        <Route path="/connexion" element={<div>écran de connexion</div>} />
        <Route path="/candidat" element={<div>espace candidat</div>} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('ProtectedRoute', () => {
  beforeEach(() => vi.clearAllMocks())

  it('status loading -> affiche le chargement', () => {
    useAuth.mockReturnValue({ status: 'loading', role: null })
    renderAt('/candidat', ['candidat'])
    expect(screen.getByRole('status')).toBeInTheDocument()
  })

  it('guest -> redirige vers /connexion', () => {
    useAuth.mockReturnValue({ status: 'guest', role: null })
    renderAt('/candidat', ['candidat'])
    expect(screen.getByText('écran de connexion')).toBeInTheDocument()
  })

  it('bon rôle -> rend le contenu protégé', () => {
    useAuth.mockReturnValue({ status: 'authenticated', role: 'candidat' })
    renderAt('/candidat', ['candidat'])
    expect(screen.getByText('contenu candidat')).toBeInTheDocument()
  })

  it('mauvais rôle -> redirige vers SON espace (roleHome)', () => {
    useAuth.mockReturnValue({ status: 'authenticated', role: 'administrateur' })
    renderAt('/candidat', ['candidat'])
    expect(screen.getByText('espace admin')).toBeInTheDocument()
    expect(screen.queryByText('contenu candidat')).not.toBeInTheDocument()
  })

  it('évaluateur sur une route candidat -> redirigé vers son espace', () => {
    useAuth.mockReturnValue({ status: 'authenticated', role: 'evaluateur' })
    renderAt('/candidat', ['candidat'])
    expect(screen.getByText('espace évaluateur')).toBeInTheDocument()
  })
})

describe('ProtectedRoute — recouvrement admin ⊇ évaluateur (Lot 8c-1, ADR-10)', () => {
  beforeEach(() => vi.clearAllMocks())

  it('candidat sur /evaluateur/* -> redirigé HORS de l’espace évaluateur', () => {
    useAuth.mockReturnValue({ status: 'authenticated', role: 'candidat' })
    renderAtEvaluateur('/evaluateur/mes-dossiers')
    expect(screen.getByText('espace candidat')).toBeInTheDocument()
    expect(screen.queryByText('contenu évaluateur')).not.toBeInTheDocument()
  })

  it('évaluateur sur /evaluateur/* -> accède (ses dossiers)', () => {
    useAuth.mockReturnValue({ status: 'authenticated', role: 'evaluateur' })
    renderAtEvaluateur('/evaluateur/mes-dossiers')
    expect(screen.getByText('contenu évaluateur')).toBeInTheDocument()
  })

  it('administrateur sur /evaluateur/* -> accède AUSSI (recouvrement)', () => {
    useAuth.mockReturnValue({ status: 'authenticated', role: 'administrateur' })
    renderAtEvaluateur('/evaluateur/mes-dossiers')
    expect(screen.getByText('contenu évaluateur')).toBeInTheDocument()
  })

  it('évaluateur sur /admin/* -> reste bloqué (le recouvrement n’est PAS symétrique)', () => {
    useAuth.mockReturnValue({ status: 'authenticated', role: 'evaluateur' })
    renderAtEvaluateur('/admin/campagnes')
    expect(screen.queryByText('contenu admin')).not.toBeInTheDocument()
  })
})
