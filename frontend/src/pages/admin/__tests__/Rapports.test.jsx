import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { apiClient } from '../../../lib/apiClient.js'
import { Rapports } from '../Rapports.jsx'

vi.mock('../../../lib/apiClient.js', () => ({
  apiClient: { get: vi.fn(), getBlob: vi.fn() },
}))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

function payload(over = {}) {
  return {
    perimetre: { campagne: { id: 'c1', nom: 'Cohorte 1 — 2026', statut: 'ouverte' }, toutes_campagnes: false, candidatures: 24, seuil_masquage: 5 },
    kpis: { candidatures: 24, eligibles: 20, evaluees: 12, retenus: 6, taux_eligibilite: 83, taux_selection: 50 },
    par_filiere: [{ filiere: { code: 'cuisine', nom: 'Cuisine' }, candidatures: 14 }, { filiere: { code: 'buanderie', nom: 'Buanderie' }, candidatures: 10 }],
    repartition_sexe: { F: 15, H: 9 },
    distribution_scores: { bornes: [0, 20, 40, 60, 80, 100], effectifs: [0, 1, 4, 5, 2] },
    repartition_decisions: { retenu: 6, liste_attente: 3, non_retenu: 3, indisponible: 0 },
    presence_entretien: { present: 11, absent: 1 },
    top_villes: [{ ville: 'Abidjan', candidatures: 18 }, { ville: 'Autres villes', candidatures: 6 }],
    ...over,
  }
}

function mockApi(data) {
  apiClient.get.mockImplementation((url) => {
    if (url.startsWith('/admin/campagnes')) return Promise.resolve({ data: [{ id: 'c1', nom: 'Cohorte 1 — 2026' }] })
    if (url.startsWith('/admin/rapports')) return Promise.resolve({ data })
    return Promise.resolve({ data: [] })
  })
}

function renderScreen() {
  return render(<MemoryRouter><Rapports /></MemoryRouter>)
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuth.mockReturnValue({ user: { profil: { prenom: 'Prisca' } }, role: 'administrateur', logout: vi.fn() })
})
afterEach(() => vi.restoreAllMocks())

describe('Rapports & statistiques (Lot 11c)', () => {
  it('affiche les agrégats renvoyés par l’API', async () => {
    mockApi(payload())
    renderScreen()

    expect(await screen.findByText('24 candidatures soumises.', { exact: false })).toBeInTheDocument()
    // KPI
    expect(screen.getByText('83 %')).toBeInTheDocument()
    expect(screen.getByText('50 %')).toBeInTheDocument()
    // graphes SVG titrés (accessibilité)
    expect(screen.getByRole('img', { name: /Candidatures par filière.*Cuisine : 14/ })).toBeInTheDocument()
    expect(screen.getByRole('img', { name: /Répartition femmes \/ hommes.*Femmes : 15/ })).toBeInTheDocument()
    expect(screen.getByRole('img', { name: /Distribution des scores.*60–80 : 5/ })).toBeInTheDocument()
    expect(screen.getByRole('img', { name: /Répartition par ville.*Autres villes : 6/ })).toBeInTheDocument()
  })

  it('une répartition masquée (null) affiche « effectif insuffisant », pas un graphe', async () => {
    mockApi(payload({ repartition_sexe: null, distribution_scores: null, top_villes: null, kpis: { ...payload().kpis, taux_selection: null } }))
    renderScreen()

    await screen.findByText(/protection contre la ré-identification/i)
    const masques = screen.getAllByText(/Effectif insuffisant pour publier cette répartition/i)
    expect(masques.length).toBeGreaterThanOrEqual(3)
    expect(screen.queryByRole('img', { name: /Répartition femmes/ })).not.toBeInTheDocument()
    // taux masqué → n/d
    expect(screen.getAllByText('n/d').length).toBeGreaterThanOrEqual(1)
  })

  it('changer de campagne relance la requête avec ?campagne=', async () => {
    mockApi(payload())
    renderScreen()
    await screen.findByText(/protection contre la ré-identification/i)

    const user = userEvent.setup()
    await user.selectOptions(screen.getByLabelText('Campagne'), 'toutes')

    await waitFor(() => expect(apiClient.get).toHaveBeenCalledWith('/admin/rapports?campagne=toutes'))
  })

  it('le bouton Exporter (CSV) déclenche le téléchargement via getBlob', async () => {
    mockApi(payload())
    apiClient.getBlob.mockResolvedValueOnce({ blob: new Blob(['a;b']), filename: 'casa-rapport.csv' })
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    HTMLAnchorElement.prototype.click = vi.fn()

    renderScreen()
    await screen.findByText(/protection contre la ré-identification/i)

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /exporter \(csv\)/i }))

    await waitFor(() => expect(apiClient.getBlob).toHaveBeenCalledWith('/admin/rapports/export.csv'))
  })

  it('les boutons Excel / PDF sont désactivés (« à venir »)', async () => {
    mockApi(payload())
    renderScreen()
    await screen.findByText(/protection contre la ré-identification/i)

    expect(screen.getByRole('button', { name: /excel/i })).toBeDisabled()
    expect(screen.getByRole('button', { name: /pdf/i })).toBeDisabled()
  })
})
