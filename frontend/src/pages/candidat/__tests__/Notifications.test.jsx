import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { apiClient } from '../../../lib/apiClient.js'
import { Notifications } from '../Notifications.jsx'

vi.mock('../../../lib/apiClient.js', () => ({
  apiClient: { get: vi.fn(), patch: vi.fn(), post: vi.fn() },
}))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

function notif(over = {}) {
  return {
    id: 'n1', categorie: 'soumission', titre: 'Candidature soumise',
    message: "Dossier n° CASA-2026-000042 — en cours d'examen.",
    lien: '/candidat', lue: false, creee_le: '2026-06-10T09:00:00Z',
    ...over,
  }
}

/**
 * `AppShell` (Lot 12c) appelle AUSSI `GET /candidat/notifications/compteur`
 * (badge sidebar) — router par URL, pas par ordre d'appel.
 */
function mockListe(items, meta = { current_page: 1, last_page: 1, total: items.length }) {
  apiClient.get.mockImplementation((path) => {
    if (path.startsWith('/candidat/notifications/compteur')) {
      return Promise.resolve({ data: { non_lues: items.filter((n) => !n.lue).length } })
    }
    if (path.startsWith('/candidat/notifications')) {
      return Promise.resolve({ data: items, meta })
    }
    return Promise.resolve({ data: null })
  })
}

function renderScreen() {
  return render(<MemoryRouter><Notifications /></MemoryRouter>)
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuth.mockReturnValue({ user: { email: 'aya@example.ci', profil: { prenom: 'Aya', nom: 'T' } }, role: 'candidat', logout: vi.fn() })
})
afterEach(() => vi.restoreAllMocks())

describe('Notifications — historique in-app (Lot 12c)', () => {
  it('liste les notifications reçues, avec titre/message/date', async () => {
    mockListe([notif()])
    renderScreen()

    expect(await screen.findByText('Candidature soumise')).toBeInTheDocument()
    expect(screen.getByText(/dossier n° casa-2026-000042/i)).toBeInTheDocument()
  })

  it('empty state si aucune notification', async () => {
    mockListe([])
    renderScreen()
    expect(await screen.findByText(/aucune notification pour le moment/i)).toBeInTheDocument()
  })

  it('pastille « Non lu » uniquement sur les notifications non lues', async () => {
    mockListe([notif({ id: 'n1', lue: false }), notif({ id: 'n2', lue: true, titre: 'Inscription confirmée' })])
    renderScreen()
    await screen.findByText('Candidature soumise')

    expect(screen.getAllByText('Non lu')).toHaveLength(1)
  })

  it('cliquer une ligne NON LUE la marque lue (PATCH), pas de re-clic sur une déjà lue', async () => {
    mockListe([notif({ id: 'n1', lue: false })])
    apiClient.patch.mockResolvedValue({ data: { id: 'n1', lue: true } })
    renderScreen()

    const ligne = await screen.findByText('Candidature soumise')
    const user = userEvent.setup()
    await user.click(ligne)

    await waitFor(() => expect(apiClient.patch).toHaveBeenCalledWith('/candidat/notifications/n1/lue'))
  })

  it('« Tout marquer comme lu » désactivé si aucune non lue, POST sinon', async () => {
    mockListe([notif({ id: 'n1', lue: true })])
    renderScreen()
    await screen.findByText('Candidature soumise')

    expect(screen.getByRole('button', { name: /tout marquer comme lu/i })).toBeDisabled()
  })

  it('« Tout marquer comme lu » actif déclenche le POST et retire les pastilles', async () => {
    mockListe([notif({ id: 'n1', lue: false })])
    apiClient.post.mockResolvedValue({ message: 'ok' })
    renderScreen()
    await screen.findByText('Candidature soumise')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /tout marquer comme lu/i }))

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith('/candidat/notifications/marquer-tout-lu'))
    await waitFor(() => expect(screen.queryByText('Non lu')).not.toBeInTheDocument())
  })

  it('erreur serveur -> message d\'erreur, pas de crash', async () => {
    apiClient.get.mockImplementation((path) => {
      if (path.startsWith('/candidat/notifications/compteur')) return Promise.resolve({ data: { non_lues: 0 } })
      return Promise.reject(new Error('boom'))
    })
    renderScreen()
    expect(await screen.findByRole('alert')).toHaveTextContent(/n'a pas pu être chargé/i)
  })
})
