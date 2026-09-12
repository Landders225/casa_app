import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { Profil } from '../Profil.jsx'

vi.mock('../../../lib/apiClient.js', () => ({
  apiClient: { get: vi.fn(), patch: vi.fn(), put: vi.fn() },
}))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

function profil(over = {}) {
  return {
    id: 'c1', prenom: 'Awa', nom: 'Koné', sexe: 'F', date_naissance: '2003-05-01',
    cni: 'CI123456789', telephone: '0700000000', ville_residence: 'Abidjan', residence_ci: true,
    ...over,
  }
}

function mockApi({ profilData = profil(), candidature = { status: 404 } } = {}) {
  apiClient.get.mockImplementation((url) => {
    if (url === '/candidat/profil') return Promise.resolve({ data: profilData })
    if (url === '/candidature') {
      if (candidature.status === 404) return Promise.reject(new ApiError('http', { status: 404 }))
      return Promise.resolve({ data: candidature.data })
    }
    return Promise.resolve({ data: null })
  })
}

function renderScreen() {
  return render(<MemoryRouter><Profil /></MemoryRouter>)
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuth.mockReturnValue({ user: { email: 'awa@cci.ci', profil: profil() }, role: 'candidat', logout: vi.fn() })
})
afterEach(() => vi.restoreAllMocks())

describe('Profil — écran « Mon profil » (Lot 13)', () => {
  it('rend les 7 champs éditables réconciliés sur le backend', async () => {
    mockApi()
    renderScreen()

    expect(await screen.findByLabelText('Prénom')).toHaveValue('Awa')
    expect(screen.getByLabelText('Nom')).toHaveValue('Koné')
    expect(screen.getByLabelText('Date de naissance')).toHaveValue('2003-05-01')
    expect(screen.getByLabelText('Sexe')).toHaveValue('F')
    expect(screen.getByLabelText('Numéro CNI / récépissé')).toHaveValue('CI123456789')
    expect(screen.getByLabelText('Téléphone')).toHaveValue('0700000000')
    expect(screen.getByLabelText('Ville de résidence')).toHaveValue('Abidjan')
  })

  it('e-mail et résidence CI sont en lecture seule, aucun champ nationalité/diplôme', async () => {
    mockApi()
    renderScreen()
    await screen.findByLabelText('Prénom')

    expect(screen.getByLabelText('E-mail')).toBeDisabled()
    expect(screen.getByLabelText('E-mail')).toHaveValue('awa@cci.ci')
    expect(screen.getByLabelText("Résidence en Côte d'Ivoire")).toBeDisabled()
    expect(screen.getByLabelText("Résidence en Côte d'Ivoire")).toHaveValue('Oui')
    expect(screen.queryByLabelText(/nationalité/i)).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/diplôme/i)).not.toBeInTheDocument()
  })

  it('affiche un âge calculé à titre indicatif', async () => {
    mockApi()
    renderScreen()
    await screen.findByLabelText('Prénom')
    expect(screen.getByText(/âge calculé/i)).toBeInTheDocument()
  })

  it('enregistre uniquement les 7 champs éditables, jamais email/residence_ci', async () => {
    mockApi()
    apiClient.patch.mockResolvedValueOnce({ data: profil({ telephone: '0102030405' }) })
    renderScreen()
    await screen.findByLabelText('Prénom')

    const user = userEvent.setup()
    await user.clear(screen.getByLabelText('Téléphone'))
    await user.type(screen.getByLabelText('Téléphone'), '0102030405')
    await user.click(screen.getByRole('button', { name: /enregistrer les modifications/i }))

    await waitFor(() => expect(apiClient.patch).toHaveBeenCalledWith('/candidat/profil', {
      prenom: 'Awa', nom: 'Koné', sexe: 'F', date_naissance: '2003-05-01',
      cni: 'CI123456789', telephone: '0102030405', ville_residence: 'Abidjan',
    }))
    const envoye = apiClient.patch.mock.calls[0][1]
    expect(envoye).not.toHaveProperty('email')
    expect(envoye).not.toHaveProperty('residence_ci')
    expect(await screen.findByText('Votre profil a été mis à jour.')).toBeInTheDocument()
  })

  it('422 (garde-fou d\'âge) -> message serveur affiché tel quel', async () => {
    mockApi()
    apiClient.patch.mockRejectedValueOnce(new ApiError('validation', {
      status: 422, message: "Le programme CASA s'adresse aux personnes de 18 à 30 ans.", errors: {},
    }))
    renderScreen()
    await screen.findByLabelText('Prénom')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /enregistrer les modifications/i }))

    expect(await screen.findByText("Le programme CASA s'adresse aux personnes de 18 à 30 ans.")).toBeInTheDocument()
  })

  it('bandeau affiché seulement si la candidature est déjà soumise', async () => {
    mockApi({ candidature: { status: 200, data: { date_soumission: '2026-06-01T00:00:00Z', statut_public: 'en_cours_de_traitement' } } })
    renderScreen()
    await screen.findByLabelText('Prénom')

    expect(await screen.findByText(/votre dossier est déjà transmis/i)).toBeInTheDocument()
  })

  it('aucun bandeau si pas encore de candidature', async () => {
    mockApi() // 404 -> status 'none'
    renderScreen()
    await screen.findByLabelText('Prénom')

    expect(screen.queryByText(/votre dossier est déjà transmis/i)).not.toBeInTheDocument()
  })

  describe('verrouillage identité post-soumission (Lot 15b)', () => {
    function mockApiSoumis() {
      mockApi({ candidature: { status: 200, data: { date_soumission: '2026-06-01T00:00:00Z', statut_public: 'en_cours_de_traitement' } } })
    }

    it('les 5 champs d\'identité sont désactivés, téléphone et ville restent actifs', async () => {
      mockApiSoumis()
      renderScreen()
      await screen.findByLabelText('Prénom')

      expect(screen.getByLabelText('Prénom')).toBeDisabled()
      expect(screen.getByLabelText('Nom')).toBeDisabled()
      expect(screen.getByLabelText('Sexe')).toBeDisabled()
      expect(screen.getByLabelText('Date de naissance')).toBeDisabled()
      expect(screen.getByLabelText('Numéro CNI / récépissé')).toBeDisabled()
      expect(screen.getByLabelText('Téléphone')).toBeEnabled()
      expect(screen.getByLabelText('Ville de résidence')).toBeEnabled()
    })

    it('le PATCH exclut les 5 champs verrouillés, même si un seul change', async () => {
      mockApiSoumis()
      apiClient.patch.mockResolvedValueOnce({ data: profil({ telephone: '0102030405' }) })
      renderScreen()
      await screen.findByLabelText('Prénom')

      const user = userEvent.setup()
      await user.clear(screen.getByLabelText('Téléphone'))
      await user.type(screen.getByLabelText('Téléphone'), '0102030405')
      await user.click(screen.getByRole('button', { name: /enregistrer les modifications/i }))

      await waitFor(() => expect(apiClient.patch).toHaveBeenCalledWith('/candidat/profil', {
        telephone: '0102030405', ville_residence: 'Abidjan',
      }))
      const envoye = apiClient.patch.mock.calls[0][1]
      for (const champ of ['prenom', 'nom', 'sexe', 'date_naissance', 'cni']) {
        expect(envoye).not.toHaveProperty(champ)
      }
    })

    it('dossier non soumis : les 7 champs restent actifs et envoyés (non-régression)', async () => {
      mockApi() // 404 -> pas encore soumis
      renderScreen()
      await screen.findByLabelText('Prénom')

      expect(screen.getByLabelText('Prénom')).toBeEnabled()
      expect(screen.getByLabelText('Sexe')).toBeEnabled()
      expect(screen.getByLabelText('Numéro CNI / récépissé')).toBeEnabled()
    })
  })

  it('la section « Sécurité » (changement de mot de passe) est présente', async () => {
    mockApi()
    renderScreen()
    await screen.findByLabelText('Prénom')
    expect(screen.getByRole('heading', { name: 'Sécurité' })).toBeInTheDocument()
    expect(screen.getByLabelText('Mot de passe actuel')).toBeInTheDocument()
  })
})
