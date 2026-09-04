import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { Campagnes } from '../Campagnes.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn(), patch: vi.fn() } }))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

function campagne(over = {}) {
  return { id: 'camp-1', nom: 'Cohorte 1 — 2026', statut: 'ouverte', date_ouverture: '2026-05-01', date_cloture: '2026-06-30', places_totales: 120, ...over }
}

function renderScreen() {
  return render(
    <MemoryRouter>
      <Campagnes />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuth.mockReturnValue({ user: { profil: { prenom: 'Admin', nom: 'Test' } }, role: 'administrateur', logout: vi.fn() })
})
afterEach(() => vi.restoreAllMocks())

describe('Campagnes — GET /admin/campagnes (nouvel endpoint) + transitions', () => {
  it('affiche la liste avec le bon bouton selon le statut renvoyé par l’API', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [campagne({ id: 'c1', statut: 'brouillon' }), campagne({ id: 'c2', statut: 'ouverte' }), campagne({ id: 'c3', statut: 'cloturee' })] })
    renderScreen()

    await screen.findAllByText('Cohorte 1 — 2026')
    expect(screen.getByRole('button', { name: 'Ouvrir la campagne' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Clôturer la campagne' })).toBeInTheDocument()
    // La campagne clôturée n'a plus aucun bouton de transition (TRANSITIONS['cloturee'] = []).
    expect(screen.getAllByRole('button', { name: /^(Ouvrir|Clôturer) la campagne$/ })).toHaveLength(2)
  })

  it('ouvrir -> confirmation -> PATCH {statut:"ouverte"}, la ligne se met à jour depuis la réponse', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [campagne({ statut: 'brouillon' })] })
    apiClient.patch.mockResolvedValueOnce({ data: { id: 'camp-1', nom: 'Cohorte 1 — 2026', statut: 'ouverte' } })
    renderScreen()
    await screen.findByText('Cohorte 1 — 2026')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Ouvrir la campagne' }))
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Ouvrir' }))

    await waitFor(() => expect(apiClient.patch).toHaveBeenCalledWith('/admin/campagnes/camp-1', { statut: 'ouverte' }))
    expect(await screen.findByText('Ouverte')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('409 (une autre campagne déjà ouverte) -> message VERBATIM affiché', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [campagne({ statut: 'brouillon' })] })
    apiClient.patch.mockRejectedValueOnce(
      new ApiError('http', { status: 409, message: 'Une autre campagne est déjà ouverte : une seule campagne peut l’être à la fois.' }),
    )
    renderScreen()
    await screen.findByText('Cohorte 1 — 2026')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Ouvrir la campagne' }))
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Ouvrir' }))

    expect(await screen.findByText(/une autre campagne est déjà ouverte/i)).toBeInTheDocument()
  })

  it('aucun bouton "Nouvelle campagne" — création hors backend (D-6a-2)', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [campagne()] })
    renderScreen()
    await screen.findByText('Cohorte 1 — 2026')
    expect(screen.queryByRole('button', { name: /nouvelle campagne/i })).not.toBeInTheDocument()
  })
})
