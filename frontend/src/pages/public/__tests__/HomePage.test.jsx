import { render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { apiClient } from '../../../lib/apiClient.js'
import { HomePage } from '../HomePage.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn() } }))

const FILIERES = [
  { code: 'cuisine', nom: 'Agent de cuisine', description: 'Préparation culinaire.', actif: true },
  { code: 'buanderie', nom: 'Agent de buanderie', description: 'Traitement du linge.', actif: true },
  { code: 'restaurant-bar', nom: 'Service restaurant-bar', description: 'Service en salle.', actif: false },
]

function renderHome() {
  return render(
    <MemoryRouter>
      <HomePage />
    </MemoryRouter>,
  )
}

describe('HomePage (accueil public)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    apiClient.get.mockResolvedValue({ data: FILIERES })
  })

  it('affiche les filières de GET /api/filieres', async () => {
    renderHome()
    expect(apiClient.get).toHaveBeenCalledWith('/filieres')
    expect(await screen.findByText('Agent de cuisine')).toBeInTheDocument()
    expect(screen.getByText('Agent de buanderie')).toBeInTheDocument()
    expect(screen.getByText('Service restaurant-bar')).toBeInTheDocument()
  })

  it('une filière inactive affiche « Actuellement fermé » et n\'est pas candidatable', async () => {
    renderHome()
    const fermee = (await screen.findByText('Service restaurant-bar')).closest('.cqp-card')
    expect(within(fermee).getByText('Actuellement fermé', { selector: '.badge' })).toBeInTheDocument()
    expect(within(fermee).getByRole('button', { name: 'Actuellement fermé' })).toBeDisabled()

    // Une filière active reste candidatable (lien vers /inscription).
    const active = (await screen.findByText('Agent de cuisine')).closest('.cqp-card')
    expect(within(active).getByRole('link', { name: /candidater/i })).toHaveAttribute('href', '/inscription')
  })

  it('affiche les critères pouvant entraîner une élimination', async () => {
    renderHome()
    await screen.findByText('Agent de cuisine')
    expect(screen.getByText('Avoir entre 18 et 30 ans')).toBeInTheDocument()
    expect(screen.getByText(/Être disponible du lundi au vendredi/)).toBeInTheDocument()
    expect(screen.getByText(/Nationalité ivoirienne confirmée par la pièce/)).toBeInTheDocument()
  })

  it('NE RÉVÈLE JAMAIS la grille de notation (§12-13)', async () => {
    const { container } = renderHome()
    await screen.findByText('Agent de cuisine')
    const html = container.innerHTML

    for (const interdit of ['/100', 'sur 100', '/65', '/35', '/ 100', 'pondération', 'pondérée', 'pondéré', 'sous-critère', 'sous-crit', 'barème']) {
      expect(html.toLowerCase()).not.toContain(interdit.toLowerCase())
    }
    // Le texte de confidentialité de la FAQ, lui, DOIT être présent.
    expect(screen.getByText(/document interne, réservé à l'équipe d'évaluation/)).toBeInTheDocument()
  })

  it('gère l\'échec de chargement des filières sans planter', async () => {
    apiClient.get.mockRejectedValueOnce(new Error('boom'))
    renderHome()
    expect(await screen.findByRole('alert')).toHaveTextContent(/filières n'a pas pu être chargée/i)
  })
})
