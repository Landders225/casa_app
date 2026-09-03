import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { apiClient } from '../../lib/apiClient.js'
import { ApiError } from '../../lib/ApiError.js'
import { AuthProvider } from '../AuthProvider.jsx'
import { useAuth } from '../useAuth.js'

// vi.mock est hissé au-dessus des imports par Vitest : `apiClient` importé ci-dessus
// est donc l'objet mocké.
vi.mock('../../lib/apiClient.js', () => ({
  apiClient: { get: vi.fn(), post: vi.fn() },
}))

function Probe() {
  const { status, role, login, logout } = useAuth()
  return (
    <div>
      <span data-testid="status">{status}</span>
      <span data-testid="role">{role ?? '-'}</span>
      <button onClick={() => login('a@b.ci', 'x').catch(() => {})}>login</button>
      <button onClick={() => logout()}>logout</button>
    </div>
  )
}

function renderAuth() {
  return render(
    <AuthProvider>
      <Probe />
    </AuthProvider>,
  )
}

describe('AuthContext', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('boot : /api/me 401 -> status guest', async () => {
    apiClient.get.mockRejectedValueOnce(new ApiError('unauthenticated', { status: 401 }))
    renderAuth()
    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('guest'))
  })

  it('boot : /api/me 200 -> authenticated + role', async () => {
    apiClient.get.mockResolvedValueOnce({ data: { id: '1', email: 'e@e.ci', role: 'candidat' } })
    renderAuth()
    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('authenticated'))
    expect(screen.getByTestId('role')).toHaveTextContent('candidat')
  })

  it('login réussi -> authenticated depuis la réponse UserResource', async () => {
    apiClient.get.mockRejectedValueOnce(new ApiError('unauthenticated', { status: 401 }))
    apiClient.post.mockResolvedValueOnce({ data: { id: '9', email: 'admin@casa.ci', role: 'administrateur' } })

    renderAuth()
    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('guest'))
    await userEvent.click(screen.getByText('login'))

    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('authenticated'))
    expect(screen.getByTestId('role')).toHaveTextContent('administrateur')
    expect(apiClient.post).toHaveBeenCalledWith('/login', { email: 'a@b.ci', password: 'x' })
  })

  it('login 422 -> reste guest (erreur propagée au formulaire)', async () => {
    apiClient.get.mockRejectedValueOnce(new ApiError('unauthenticated', { status: 401 }))
    apiClient.post.mockRejectedValueOnce(
      new ApiError('validation', { status: 422, errors: { email: ['E-mail ou mot de passe incorrect.'] } }),
    )

    renderAuth()
    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('guest'))
    await userEvent.click(screen.getByText('login'))

    // laisse le temps à une éventuelle bascule d'état
    await new Promise((r) => setTimeout(r, 10))
    expect(screen.getByTestId('status')).toHaveTextContent('guest')
  })

  it('logout -> guest', async () => {
    apiClient.get.mockResolvedValueOnce({ data: { id: '1', email: 'e@e.ci', role: 'candidat' } })
    apiClient.post.mockResolvedValueOnce(null)

    renderAuth()
    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('authenticated'))
    await userEvent.click(screen.getByText('logout'))

    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('guest'))
    expect(apiClient.post).toHaveBeenCalledWith('/logout')
  })
})
