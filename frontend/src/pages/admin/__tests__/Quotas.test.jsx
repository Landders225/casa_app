import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { Quotas } from '../Quotas.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

function campagne(over = {}) {
  return {
    id: 'camp-1',
    nom: 'Cohorte 1 — 2026',
    statut: 'ouverte',
    date_ouverture: '2026-05-01',
    date_cloture: '2026-06-30',
    places_totales: 48,
    classement_calcule: false,
    classement_perime: false,
    publiee: false,
    filieres: [
      { id: 'f-cuisine', code: 'cuisine', nom: 'Cuisine', quota: 24 },
      { id: 'f-buanderie', code: 'buanderie', nom: 'Buanderie', quota: 24 },
    ],
    ...over,
  }
}

function filiere(over = {}) {
  return { id: 'f-cuisine', code: 'cuisine', nom: 'Cuisine', description: '', actif: true, ...over }
}

function renderScreen() {
  return render(
    <MemoryRouter>
      <Quotas />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuth.mockReturnValue({ user: { profil: { prenom: 'Admin', nom: 'Test' } }, role: 'administrateur', logout: vi.fn() })
})
afterEach(() => vi.restoreAllMocks())

describe('Quotas (Lot 17) — création + édition nom/dates + édition quotas', () => {
  it('affiche la liste des campagnes avec les quotas par filière pré-remplis', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [campagne()] })
    renderScreen()

    await screen.findByText('Cohorte 1 — 2026')
    expect(screen.getByLabelText('Quota Cuisine — Cohorte 1 — 2026')).toHaveValue(24)
    expect(screen.getByLabelText('Quota Buanderie — Cohorte 1 — 2026')).toHaveValue(24)
  })

  it('crée une campagne (toujours en brouillon) -> POST /admin/campagnes, la nouvelle carte apparaît', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [] }).mockResolvedValueOnce({ data: [filiere()] })
    apiClient.post.mockResolvedValueOnce({ data: campagne({ id: 'camp-2', nom: 'Cohorte 2 — 2027', statut: 'brouillon' }) })
    renderScreen()
    await screen.findByText('Aucune campagne pour le moment.')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /créer une campagne/i }))
    await screen.findByRole('dialog', { name: /créer une campagne/i })

    await user.type(screen.getByLabelText('Nom de la campagne'), 'Cohorte 2 — 2027')
    await user.type(screen.getByLabelText("Date d'ouverture prévue"), '2027-01-01')
    await user.type(screen.getByLabelText('Date de clôture prévue'), '2027-02-28')
    await user.click(screen.getByLabelText('Cuisine'))
    await user.click(screen.getByRole('button', { name: /créer la campagne/i }))

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith('/admin/campagnes', {
      nom: 'Cohorte 2 — 2027',
      date_ouverture: '2027-01-01',
      date_cloture: '2027-02-28',
      filieres: [{ filiere_id: 'f-cuisine', quota: 24 }],
    }))
    expect(await screen.findByText('Cohorte 2 — 2027')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('422 à la création -> message + erreurs de champ affichés, le modal reste ouvert', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [] }).mockResolvedValueOnce({ data: [filiere()] })
    apiClient.post.mockRejectedValueOnce(new ApiError('validation', {
      status: 422, message: 'Corrigez les champs en rouge.', errors: { nom: ['Le nom est requis.'] },
    }))
    renderScreen()
    await screen.findByText('Aucune campagne pour le moment.')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /créer une campagne/i }))
    await screen.findByRole('dialog')
    await user.type(screen.getByLabelText('Nom de la campagne'), 'x') // le serveur rejette quand même (422 simulé ci-dessous)
    await user.type(screen.getByLabelText("Date d'ouverture prévue"), '2027-01-01')
    await user.type(screen.getByLabelText('Date de clôture prévue'), '2027-02-28')
    await user.click(screen.getByLabelText('Cuisine'))
    await user.click(screen.getByRole('button', { name: /créer la campagne/i }))

    expect(await screen.findByText('Le nom est requis.')).toBeInTheDocument()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('modifie le nom/les dates -> PUT /admin/campagnes/{id}, la carte se met à jour', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [campagne()] })
    apiClient.put.mockResolvedValueOnce({ data: campagne({ nom: 'Cohorte 1 — 2026 (renommée)' }) })
    renderScreen()
    await screen.findByText('Cohorte 1 — 2026')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /modifier le nom/i }))
    const dialog = await screen.findByRole('dialog')
    const champNom = within(dialog).getByLabelText('Nom de la campagne')
    await user.clear(champNom)
    await user.type(champNom, 'Cohorte 1 — 2026 (renommée)')
    await user.click(within(dialog).getByRole('button', { name: /enregistrer/i }))

    await waitFor(() => expect(apiClient.put).toHaveBeenCalledWith('/admin/campagnes/camp-1', {
      nom: 'Cohorte 1 — 2026 (renommée)', date_ouverture: '2026-05-01', date_cloture: '2026-06-30',
    }))
    expect(await screen.findByText('Cohorte 1 — 2026 (renommée)')).toBeInTheDocument()
  })

  it('campagne clôturée : les champs date du modal sont désactivés, un avertissement est affiché', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [campagne({ statut: 'cloturee' })] })
    renderScreen()
    await screen.findByText('Cohorte 1 — 2026')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /modifier le nom/i }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByLabelText("Date d'ouverture prévue")).toBeDisabled()
    expect(within(dialog).getByLabelText('Date de clôture prévue')).toBeDisabled()
    expect(within(dialog).getByLabelText('Nom de la campagne')).toBeEnabled()
    expect(within(dialog).getByText(/dates sont verrouillées/i)).toBeInTheDocument()
  })

  it('quota sans classement calculé : enregistrement direct, sans confirmation', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [campagne()] })
    apiClient.put.mockResolvedValueOnce({ data: campagne({ places_totales: 54, filieres: [{ id: 'f-cuisine', code: 'cuisine', nom: 'Cuisine', quota: 30 }, { id: 'f-buanderie', code: 'buanderie', nom: 'Buanderie', quota: 24 }] }) })
    renderScreen()
    await screen.findByText('Cohorte 1 — 2026')

    const user = userEvent.setup()
    const champCuisine = screen.getByLabelText('Quota Cuisine — Cohorte 1 — 2026')
    await user.clear(champCuisine)
    await user.type(champCuisine, '30')
    await user.click(screen.getByRole('button', { name: /enregistrer les quotas/i }))

    await waitFor(() => expect(apiClient.put).toHaveBeenCalledWith('/admin/campagnes/camp-1/quotas', {
      quotas: [{ filiere_id: 'f-cuisine', quota: 30 }, { filiere_id: 'f-buanderie', quota: 24 }],
    }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('quota AVEC classement déjà calculé : une confirmation explicite est requise avant le PUT', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [campagne({ classement_calcule: true })] })
    apiClient.put.mockResolvedValueOnce({ data: campagne({ classement_calcule: true, classement_perime: true }) })
    renderScreen()
    await screen.findByText('Cohorte 1 — 2026')
    expect(screen.getByText(/classement a déjà été calculé/i)).toBeInTheDocument()

    const user = userEvent.setup()
    const champCuisine = screen.getByLabelText('Quota Cuisine — Cohorte 1 — 2026')
    await user.clear(champCuisine)
    await user.type(champCuisine, '5')
    await user.click(screen.getByRole('button', { name: /enregistrer les quotas/i }))

    // Pas encore appelé : la confirmation est requise en premier.
    expect(apiClient.put).not.toHaveBeenCalled()
    const dialog = await screen.findByRole('dialog', { name: /périmer le classement/i })
    expect(within(dialog).getByText(/périmé/i)).toBeInTheDocument()

    await user.click(within(dialog).getByRole('button', { name: /modifier le quota/i }))
    await waitFor(() => expect(apiClient.put).toHaveBeenCalledWith('/admin/campagnes/camp-1/quotas', expect.any(Object)))
  })

  it('classement_perime : avertissement bloquant affiché, distinct du simple avertissement "déjà calculé"', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [campagne({ classement_calcule: true, classement_perime: true })] })
    renderScreen()
    await screen.findByText('Cohorte 1 — 2026')

    expect(screen.getByText('Classement périmé')).toBeInTheDocument()
    expect(screen.getByText(/ne reflètent plus les quotas actuels/i)).toBeInTheDocument()
  })

  it('campagne publiée : quotas verrouillés, pas de bouton "Enregistrer les quotas"', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [campagne({ publiee: true })] })
    renderScreen()
    await screen.findByText('Cohorte 1 — 2026')

    expect(screen.getByText(/résultats publiés/i)).toBeInTheDocument()
    expect(screen.getByLabelText('Quota Cuisine — Cohorte 1 — 2026')).toBeDisabled()
    expect(screen.queryByRole('button', { name: /enregistrer les quotas/i })).not.toBeInTheDocument()
  })
})
