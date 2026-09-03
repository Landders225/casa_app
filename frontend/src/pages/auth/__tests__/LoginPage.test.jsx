import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { ApiError } from '../../../lib/ApiError.js'
import { LoginPage } from '../LoginPage.jsx'

vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

function renderLogin() {
  return render(
    <MemoryRouter initialEntries={['/connexion']}>
      <Routes>
        <Route path="/connexion" element={<LoginPage />} />
        <Route path="/candidat" element={<div>espace candidat</div>} />
        <Route path="/admin" element={<div>espace admin</div>} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('LoginPage', () => {
  beforeEach(() => vi.clearAllMocks())

  it('rend le formulaire et les comptes de démonstration', () => {
    useAuth.mockReturnValue({ login: vi.fn() })
    renderLogin()
    expect(screen.getByRole('heading', { name: /accéder à mon espace/i })).toBeInTheDocument()
    expect(screen.getByText('candidat@casa-demo.ci')).toBeInTheDocument()
    expect(screen.getByText('evaluateur@casa-demo.ci')).toBeInTheDocument()
    expect(screen.getByText('admin@casa-demo.ci')).toBeInTheDocument()
  })

  it('« Utiliser » pré-remplit les identifiants de démo', async () => {
    useAuth.mockReturnValue({ login: vi.fn() })
    renderLogin()
    await userEvent.click(screen.getAllByRole('button', { name: 'Utiliser' })[0])
    expect(screen.getByLabelText('Adresse e-mail')).toHaveValue('candidat@casa-demo.ci')
    expect(screen.getByLabelText('Mot de passe')).toHaveValue('Demo2026!')
  })

  it('connexion réussie -> redirige vers l’espace du rôle', async () => {
    const login = vi.fn().mockResolvedValue({ role: 'administrateur' })
    useAuth.mockReturnValue({ login })
    renderLogin()

    await userEvent.type(screen.getByLabelText('Adresse e-mail'), 'admin@casa-demo.ci')
    await userEvent.type(screen.getByLabelText('Mot de passe'), 'Demo2026!')
    await userEvent.click(screen.getByRole('button', { name: /se connecter/i }))

    expect(login).toHaveBeenCalledWith('admin@casa-demo.ci', 'Demo2026!')
    expect(await screen.findByText('espace admin')).toBeInTheDocument()
  })

  it('échec 422 -> affiche le message générique du backend, pas de redirection', async () => {
    const login = vi
      .fn()
      .mockRejectedValue(new ApiError('validation', { status: 422, errors: { email: ['E-mail ou mot de passe incorrect.'] } }))
    useAuth.mockReturnValue({ login })
    renderLogin()

    await userEvent.type(screen.getByLabelText('Adresse e-mail'), 'x@y.ci')
    await userEvent.type(screen.getByLabelText('Mot de passe'), 'faux')
    await userEvent.click(screen.getByRole('button', { name: /se connecter/i }))

    expect(await screen.findByRole('alert')).toHaveTextContent('E-mail ou mot de passe incorrect.')
    expect(screen.queryByText('espace candidat')).not.toBeInTheDocument()
    expect(screen.queryByText('espace admin')).not.toBeInTheDocument()
  })
})
