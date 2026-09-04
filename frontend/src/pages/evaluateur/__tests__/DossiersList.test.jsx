import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { apiClient } from '../../../lib/apiClient.js'
import { DossiersList } from '../DossiersList.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn() } }))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

const DOSSIERS_2 = {
  data: [
    {
      id: 'c-1', numero_dossier: 'CASA-2026-000001', statut_interne: 'soumis', statut_eligibilite_interne: 'eligible',
      date_soumission: '2026-06-01T00:00:00Z', verification_faite: false,
      candidat: { prenom: 'Awa', nom: 'Konan', ville_residence: 'Abidjan' },
      filiere: { code: 'cuisine', nom: 'Agent de cuisine' },
    },
    {
      id: 'c-2', numero_dossier: 'CASA-2026-000002', statut_interne: 'en_instruction', statut_eligibilite_interne: 'non_eligible',
      date_soumission: '2026-06-02T00:00:00Z', verification_faite: true,
      candidat: { prenom: 'Koffi', nom: 'Yao', ville_residence: 'Bouaké' },
      filiere: { code: 'buanderie', nom: 'Agent de buanderie' },
    },
  ],
  meta: { current_page: 1, last_page: 1, total: 2 },
}

function renderList() {
  return render(
    <MemoryRouter>
      <DossiersList />
    </MemoryRouter>,
  )
}

describe('DossiersList', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useAuth.mockReturnValue({ role: 'evaluateur' })
    apiClient.get.mockImplementation((path) => {
      if (path.startsWith('/filieres')) return Promise.resolve({ data: [] })
      return Promise.resolve(DOSSIERS_2)
    })
  })
  afterEach(() => vi.restoreAllMocks())

  it('n’affiche QUE les dossiers renvoyés par l’API — rien de plus, rien de moins', async () => {
    renderList()
    expect(await screen.findByText('Awa Konan')).toBeInTheDocument()
    expect(screen.getByText('Koffi Yao')).toBeInTheDocument()
    // exactement 2 lignes de données (+ 1 ligne d'en-tête)
    expect(screen.getAllByRole('row')).toHaveLength(3)
    expect(apiClient.get).toHaveBeenCalledWith('/evaluateur/candidatures')
  })

  it('« Mes dossiers » pour un évaluateur, « Tous les dossiers » pour un admin (recouvrement)', async () => {
    const { unmount } = renderList()
    expect(await screen.findByRole('heading', { level: 2, name: 'Mes dossiers' })).toBeInTheDocument()
    unmount()

    useAuth.mockReturnValue({ role: 'administrateur' })
    renderList()
    expect(await screen.findByRole('heading', { level: 2, name: 'Tous les dossiers' })).toBeInTheDocument()
  })

  it('changer d’onglet relance la requête avec le bon filtre statut_interne', async () => {
    renderList()
    await screen.findByText('Awa Konan')
    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'En instruction' }))
    await waitFor(() =>
      expect(apiClient.get).toHaveBeenCalledWith('/evaluateur/candidatures?statut_interne=en_instruction'),
    )
  })

  it('liste vide -> message explicite, pas de crash', async () => {
    apiClient.get.mockImplementation((path) => {
      if (path.startsWith('/filieres')) return Promise.resolve({ data: [] })
      return Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
    })
    renderList()
    expect(await screen.findByText(/aucun dossier/i)).toBeInTheDocument()
  })
})
