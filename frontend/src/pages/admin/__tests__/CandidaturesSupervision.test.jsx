import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { CandidaturesSupervision } from '../CandidaturesSupervision.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn(), patch: vi.fn(), post: vi.fn() } }))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

const EVALUATEURS = [
  { id: 'eval-1', prenom: 'Yann', nom: 'Kacou', poste: "Chargé d'évaluation" },
  { id: 'eval-2', prenom: 'Aya', nom: 'Bamba', poste: "Chargée d'évaluation" },
]
const CAMPAGNES = [{ id: 'camp-1', nom: 'Cohorte 1 — 2026', statut: 'ouverte', date_ouverture: '2026-05-01', date_cloture: '2026-06-30', places_totales: 120 }]
const FILIERES = [{ id: 'f1', code: 'cuisine', nom: 'Agent de cuisine', description: '', actif: true }]

function candidature(over = {}) {
  return {
    id: 'c-1',
    numero_dossier: 'CASA-2026-000001',
    statut_interne: 'soumis',
    statut_eligibilite_interne: 'non_verifie',
    date_soumission: '2026-06-01T00:00:00Z',
    dossier_verrouille: false,
    candidat: { prenom: 'Awa', nom: 'Konan', ville_residence: 'Abidjan - Cocody' },
    filiere: { code: 'cuisine', nom: 'Agent de cuisine' },
    evaluateur: null,
    score_dossier: null,
    score_entretien: null,
    decision: null,
    ...over,
  }
}

function mockGet({ candidatures = [candidature()], meta = { current_page: 1, last_page: 1, total: 1 } } = {}) {
  apiClient.get.mockImplementation((path) => {
    if (path.startsWith('/admin/candidatures')) return Promise.resolve({ data: candidatures, meta })
    if (path === '/admin/evaluateurs') return Promise.resolve({ data: EVALUATEURS })
    if (path === '/admin/campagnes') return Promise.resolve({ data: CAMPAGNES })
    if (path === '/filieres') return Promise.resolve({ data: FILIERES })
    return Promise.resolve({ data: [] })
  })
}

function renderScreen() {
  return render(
    <MemoryRouter>
      <CandidaturesSupervision />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuth.mockReturnValue({
    user: { email: 'admin@casa-demo.ci', profil: { prenom: 'Admin', nom: 'Test' } },
    role: 'administrateur',
    logout: vi.fn(),
  })
})
afterEach(() => vi.restoreAllMocks())

describe('CandidaturesSupervision — affichage', () => {
  it("affiche les dossiers renvoyés par l'API, rien de plus", async () => {
    mockGet()
    renderScreen()

    expect(await screen.findByText('CASA-2026-000001')).toBeInTheDocument()
    expect(screen.getByText('Awa Konan')).toBeInTheDocument()
    expect(within(screen.getByRole('table')).getByText('Non affecté')).toBeInTheDocument()
  })

  it('changer un filtre relance la requête avec le bon paramètre', async () => {
    mockGet()
    renderScreen()
    await screen.findByText('CASA-2026-000001')

    const user = userEvent.setup()
    await user.selectOptions(screen.getByDisplayValue('Tous les statuts'), 'en_instruction')

    await waitFor(() => {
      const called = apiClient.get.mock.calls.some(([path]) => path === '/admin/candidatures?statut_interne=en_instruction')
      expect(called).toBe(true)
    })
  })
})

describe('CandidaturesSupervision — affectation en masse (atomique)', () => {
  it('sélection -> affecter -> succès -> POST correct, sélection vidée, liste rechargée', async () => {
    mockGet()
    apiClient.post.mockResolvedValueOnce({ data: { affectees: 1, evaluateur: { id: 'eval-1', prenom: 'Yann', nom: 'Kacou' } } })
    renderScreen()
    await screen.findByText('CASA-2026-000001')

    const user = userEvent.setup()
    await user.click(screen.getByRole('checkbox', { name: /sélectionner casa-2026-000001/i }))
    await user.click(screen.getByRole('button', { name: /affecter \(1\)/i }))

    const dialog = screen.getByRole('dialog', { name: 'Affecter à un évaluateur' })
    await user.selectOptions(dialog.querySelector('select'), 'eval-1')
    await user.click(screen.getByRole('button', { name: 'Affecter' }))

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledTimes(1))
    expect(apiClient.post).toHaveBeenCalledWith('/admin/affectations', { evaluateur_id: 'eval-1', candidature_ids: ['c-1'] })

    // Le modal se ferme, la sélection est vidée, la liste est rechargée (2e GET).
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /affecter \(/i })).not.toBeInTheDocument()
    await waitFor(() => expect(apiClient.get.mock.calls.filter(([p]) => p.startsWith('/admin/candidatures')).length).toBeGreaterThanOrEqual(2))
  })

  it('422 atomique -> message VERBATIM affiché, sélection PRÉSERVÉE (le modal reste ouvert)', async () => {
    mockGet()
    apiClient.post.mockRejectedValueOnce(
      new ApiError('validation', {
        status: 422,
        errors: { candidature_ids: ['Non affectable(s) (doit être « soumise » ou « en instruction ») : CASA-2026-000001. Aucune affectation effectuée.'] },
      }),
    )
    renderScreen()
    await screen.findByText('CASA-2026-000001')

    const user = userEvent.setup()
    await user.click(screen.getByRole('checkbox', { name: /sélectionner casa-2026-000001/i }))
    await user.click(screen.getByRole('button', { name: /affecter \(1\)/i }))
    const dialog = screen.getByRole('dialog', { name: 'Affecter à un évaluateur' })
    await user.selectOptions(dialog.querySelector('select'), 'eval-1')
    await user.click(screen.getByRole('button', { name: 'Affecter' }))

    const alerte = await screen.findByRole('alert')
    expect(alerte).toHaveTextContent(/aucune affectation effectuée/i)
    expect(alerte).toHaveTextContent(/CASA-2026-000001/)
    // Le modal reste ouvert, la sélection n'est PAS vidée : la case reste cochée.
    expect(screen.getByRole('dialog', { name: 'Affecter à un évaluateur' })).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: /sélectionner casa-2026-000001/i })).toBeChecked()
  })
})

describe('CandidaturesSupervision — élimination manuelle mono-cible (Lot 8d-3, D-6b-4)', () => {
  it('précondition : bouton désactivé si déjà non éligible', async () => {
    mockGet({ candidatures: [candidature({ statut_eligibilite_interne: 'non_eligible' })] })
    renderScreen()
    await screen.findByText('CASA-2026-000001')
    expect(screen.getByRole('button', { name: 'Éliminer' })).toBeDisabled()
  })

  it('motif obligatoire (bouton bloqué), succès -> POST mono-cible puis liste rechargée', async () => {
    mockGet()
    renderScreen()
    await screen.findByText('CASA-2026-000001')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Éliminer' }))

    const dialog = screen.getByRole('dialog', { name: 'Éliminer ce dossier ?' })
    const confirmBtn = within(dialog).getByRole('button', { name: 'Éliminer' })
    expect(confirmBtn).toBeDisabled()
    await user.type(within(dialog).getByLabelText(/motif/i), 'Pièce falsifiée')
    expect(confirmBtn).toBeEnabled()

    apiClient.post.mockResolvedValueOnce({ data: { numero_dossier: 'CASA-2026-000001', statut_eligibilite_interne: 'non_eligible' } })
    const getCallsAvant = apiClient.get.mock.calls.filter(([p]) => p.startsWith('/admin/candidatures')).length
    await user.click(confirmBtn)

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith('/admin/candidatures/c-1/elimination', { motif: 'Pièce falsifiée' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    // Mono-cible (D-6b-4) : un seul candidat visé par le POST, pas d'endpoint bulk.
    await waitFor(() => expect(apiClient.get.mock.calls.filter(([p]) => p.startsWith('/admin/candidatures')).length).toBeGreaterThan(getCallsAvant))
  })

  it('422 backend -> message affiché verbatim, le modal reste ouvert', async () => {
    mockGet()
    apiClient.post.mockRejectedValueOnce(new ApiError('validation', { status: 422, message: 'Le motif doit contenir au moins 3 caractères.' }))
    renderScreen()
    await screen.findByText('CASA-2026-000001')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Éliminer' }))
    const dialog = screen.getByRole('dialog', { name: 'Éliminer ce dossier ?' })
    await user.type(within(dialog).getByLabelText(/motif/i), 'Motif suffisant')
    await user.click(within(dialog).getByRole('button', { name: 'Éliminer' }))

    expect(await screen.findByText(/le motif doit contenir au moins 3 caractères/i)).toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: 'Éliminer ce dossier ?' })).toBeInTheDocument()
  })
})
