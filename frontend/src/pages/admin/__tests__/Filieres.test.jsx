import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { apiClient } from '../../../lib/apiClient.js'
import { Filieres } from '../Filieres.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn(), patch: vi.fn() } }))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

function filiere(over = {}) {
  return { id: 'f1', code: 'cuisine', nom: 'Agent de cuisine', description: 'Formation en cuisine.', actif: true, ...over }
}

function renderScreen() {
  return render(
    <MemoryRouter>
      <Filieres />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuth.mockReturnValue({ user: { profil: { prenom: 'Admin', nom: 'Test' } }, role: 'administrateur', logout: vi.fn() })
})
afterEach(() => vi.restoreAllMocks())

describe('Filieres — réutilise GET /api/filieres (public), toggle réel', () => {
  it("affiche la liste depuis l'endpoint public, actif/inactif reflété", async () => {
    apiClient.get.mockResolvedValueOnce({ data: [filiere(), filiere({ id: 'f2', code: 'menage', nom: 'Agent de ménage', actif: false })] })
    renderScreen()

    expect(await screen.findByText('Agent de cuisine')).toBeInTheDocument()
    expect(apiClient.get).toHaveBeenCalledWith('/filieres')
    expect(screen.getByText('Active — candidatures ouvertes')).toBeInTheDocument()
    expect(screen.getByText('Inactive — « Actuellement fermé » côté public')).toBeInTheDocument()
  })

  it('bascule le switch -> PATCH /admin/filieres/{id}, le badge se met à jour depuis la réponse', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [filiere()] })
    apiClient.patch.mockResolvedValueOnce({ data: { id: 'f1', code: 'cuisine', nom: 'Agent de cuisine', actif: false } })
    renderScreen()
    await screen.findByText('Agent de cuisine')

    const user = userEvent.setup()
    await user.click(screen.getByRole('checkbox', { name: /désactiver agent de cuisine/i }))

    await waitFor(() => expect(apiClient.patch).toHaveBeenCalledWith('/admin/filieres/f1', { actif: false }))
    expect(await screen.findByText('Inactive — « Actuellement fermé » côté public')).toBeInTheDocument()
  })

  it("aucun bouton d'édition (nom/description/quota) ni de création — hors backend (D-6a-2)", async () => {
    apiClient.get.mockResolvedValueOnce({ data: [filiere()] })
    renderScreen()
    await screen.findByText('Agent de cuisine')

    expect(screen.queryByRole('button', { name: /modifier/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /ajouter/i })).not.toBeInTheDocument()
  })
})
