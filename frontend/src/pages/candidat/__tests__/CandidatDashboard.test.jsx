import { render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { useAuth } from '../../../auth/useAuth.js'
import { CandidatDashboard } from '../CandidatDashboard.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn() } }))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

const TERMES_INTERNES =
  /score|\brang\b|bar[èe]me|[ée]ligib|[ée]valuat|\bnote\b|\/(35|65|100)|\bpoints?\b|pond[ée]r|non[_ -]?eligible/i

function renderDashboard() {
  return render(
    <MemoryRouter>
      <CandidatDashboard />
    </MemoryRouter>,
  )
}

/**
 * `AppShell` (Lot 12c) appelle AUSSI `GET /candidat/notifications/compteur`
 * (badge de la sidebar) — dès le premier rendu (état "loading" inclus), donc
 * AVANT même l'effet de `useMaCandidature`. Le mock doit donc router par URL,
 * pas par ordre d'appel (`mockResolvedValueOnce` serait consommé par le badge).
 */
function mockCandidatureResponse(fabriqueReponse) {
  apiClient.get.mockImplementation((path) => {
    if (path === '/candidature') return fabriqueReponse()
    return Promise.resolve({ data: { non_lues: 0 } })
  })
}

describe('CandidatDashboard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useAuth.mockReturnValue({
      user: { email: 'aya@example.ci', profil: { prenom: 'Aya', nom: 'T' } },
      role: 'candidat',
      logout: vi.fn(),
    })
  })

  it('GET /api/candidature 404 -> état d\'accueil « compte créé » + CTA Commencer', async () => {
    mockCandidatureResponse(() => Promise.reject(new ApiError('http', { status: 404 })))
    renderDashboard()

    expect(await screen.findByText('Votre compte a été créé !')).toBeInTheDocument()
    expect(screen.getByText(/Bonjour Aya/)).toBeInTheDocument()
    expect(screen.getByText(/pas encore de dossier de candidature/i)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Commencer' })).toHaveAttribute('href', '/candidat/candidature')
  })

  it('brouillon -> « Reprendre » vers le wizard', async () => {
    mockCandidatureResponse(() => Promise.resolve({
      data: { numero_dossier: 'CASA-2026-000001', statut_public: 'brouillon', filiere: { nom: 'Agent de cuisine' } },
    }))
    renderDashboard()
    expect(await screen.findByRole('link', { name: 'Reprendre' })).toHaveAttribute('href', '/candidat/candidature')
  })

  it('en_cours_de_traitement -> statut + numéro + « Suivre ma candidature » vers le suivi', async () => {
    mockCandidatureResponse(() => Promise.resolve({
      data: { numero_dossier: 'CASA-2026-000042', statut_public: 'en_cours_de_traitement', filiere: { nom: 'Agent de cuisine' } },
    }))
    renderDashboard()

    expect(await screen.findByText('En cours de traitement')).toBeInTheDocument()
    expect(screen.getByText('N° CASA-2026-000042')).toBeInTheDocument()
    expect(screen.getByText('Agent de cuisine')).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: /suivre ma candidature/i })[0]).toHaveAttribute('href', '/candidat/ma-candidature')
  })

  it('decision_publiee -> « Voir mon résultat » vers le suivi', async () => {
    mockCandidatureResponse(() => Promise.resolve({
      data: { numero_dossier: 'CASA-2026-000042', statut_public: 'decision_publiee', decision: 'retenu', filiere: { nom: 'Agent de cuisine' } },
    }))
    renderDashboard()
    const liens = await screen.findAllByRole('link', { name: /voir mon résultat/i })
    expect(liens[0]).toHaveAttribute('href', '/candidat/ma-candidature')
    // le dashboard ne rend PAS la décision elle-même — juste l'invitation à la consulter
    expect(screen.queryByText(/félicitations|retenue/i)).toBeNull()
  })

  it('erreur serveur -> message d\'erreur, pas de crash', async () => {
    mockCandidatureResponse(() => Promise.reject(new ApiError('server', { status: 500 })))
    renderDashboard()
    expect(await screen.findByRole('alert')).toHaveTextContent(/n'a pas pu être chargé/i)
  })

  it('stepper : 4 étapes coarse, aucune étape interne, quel que soit le statut', async () => {
    mockCandidatureResponse(() => Promise.resolve({
      data: { numero_dossier: 'CASA-2026-000042', statut_public: 'en_cours_de_traitement', filiere: { nom: 'X' } },
    }))
    const { container } = renderDashboard()
    await screen.findByText('En cours de traitement')
    const stepper = container.querySelector('.stepper')
    expect(within(stepper).getAllByText(/./).map((n) => n.textContent).join(' ')).not.toMatch(
      /entretien|[ée]valuation|[ée]ligibilit|instruction/i,
    )
    expect(container.textContent).not.toMatch(TERMES_INTERNES)
  })
})
