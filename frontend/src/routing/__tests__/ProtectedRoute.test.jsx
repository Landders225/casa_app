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
