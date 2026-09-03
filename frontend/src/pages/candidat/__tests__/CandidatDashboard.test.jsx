import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { useAuth } from '../../../auth/useAuth.js'
import { CandidatDashboard } from '../CandidatDashboard.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn(), post: vi.fn() } }))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

function renderDashboard() {
  return render(
    <MemoryRouter>
      <CandidatDashboard />
    </MemoryRouter>,
  )
}

describe('CandidatDashboard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useAuth.mockReturnValue({ user: { email: 'aya@example.ci', role: 'candidat', profil: { prenom: 'Aya', nom: 'T' } }, logout: vi.fn() })
  })

  it('GET /api/candidature 404 -> état d\'accueil « compte créé »', async () => {
    apiClient.get.mockRejectedValueOnce(new ApiError('http', { status: 404 }))
    renderDashboard()

    expect(await screen.findByText('Votre compte a été créé !')).toBeInTheDocument()
    expect(screen.getByText(/Bonjour Aya/)).toBeInTheDocument()
    expect(screen.getByText(/pas encore de dossier de candidature/i)).toBeInTheDocument()
  })

  it('GET /api/candidature 200 -> affiche le statut public et le numéro', async () => {
    apiClient.get.mockResolvedValueOnce({
      data: { numero_dossier: 'CASA-2026-000042', statut_public: 'en_cours_de_traitement', filiere: { nom: 'Agent de cuisine' } },
    })
    renderDashboard()

    expect(await screen.findByText('En cours de traitement')).toBeInTheDocument()
    expect(screen.getByText('N° CASA-2026-000042')).toBeInTheDocument()
    expect(screen.getByText('Agent de cuisine')).toBeInTheDocument()
  })

  it('erreur serveur -> message d\'erreur, pas de crash', async () => {
    apiClient.get.mockRejectedValueOnce(new ApiError('server', { status: 500 }))
    renderDashboard()
    expect(await screen.findByRole('alert')).toHaveTextContent(/n'a pas pu être chargé/i)
  })
})
