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

  it('rend le formulaire de connexion', () => {
    useAuth.mockReturnValue({ login: vi.fn() })
    renderLogin()
    expect(screen.getByRole('heading', { name: /accéder à mon espace/i })).toBeInTheDocument()
    expect(screen.getByLabelText('Adresse e-mail')).toBeInTheDocument()
    expect(screen.getByLabelText('Mot de passe')).toBeInTheDocument()
  })

  it('n’expose AUCUN compte de démonstration (Lot 10 — sécurité)', () => {
    useAuth.mockReturnValue({ login: vi.fn() })
    const { container } = renderLogin()
    // Ni e-mail privilégié, ni mot de passe, ni bouton « Utiliser » : la page
    // publique ne divulgue rien (le bundle JS est téléchargeable).
    expect(container.textContent).not.toMatch(/casa-demo\.ci/i)
    expect(container.textContent).not.toMatch(/Demo2026/)
    expect(screen.queryByText(/comptes de démonstration/i)).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Utiliser' })).not.toBeInTheDocument()
  })

  it('connexion réussie -> redirige vers l’espace du rôle', async () => {
    const login = vi.fn().mockResolvedValue({ role: 'administrateur' })
    useAuth.mockReturnValue({ login })
    renderLogin()

    await userEvent.type(screen.getByLabelText('Adresse e-mail'), 'prisca.yeo@cci.ci')
    await userEvent.type(screen.getByLabelText('Mot de passe'), 'UnMotDePasse2026')
    await userEvent.click(screen.getByRole('button', { name: /se connecter/i }))

    expect(login).toHaveBeenCalledWith('prisca.yeo@cci.ci', 'UnMotDePasse2026')
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

  it('429 -> message « trop de tentatives »', async () => {
    const login = vi.fn().mockRejectedValue(new ApiError('rate_limited', { status: 429 }))
    useAuth.mockReturnValue({ login })
    renderLogin()

    await userEvent.type(screen.getByLabelText('Adresse e-mail'), 'x@y.ci')
    await userEvent.type(screen.getByLabelText('Mot de passe'), 'faux')
    await userEvent.click(screen.getByRole('button', { name: /se connecter/i }))

    expect(await screen.findByRole('alert')).toHaveTextContent(/trop de tentatives/i)
  })
})
