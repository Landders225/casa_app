import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { apiClient } from '../../../lib/apiClient.js'
import { Audit } from '../Audit.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn() } }))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

function ligne(over = {}) {
  return {
    id: 'a1',
    horodatage: '2026-06-10T09:00:00Z',
    auteur: { email: 'admin@casa-demo.ci', role: 'administrateur', nom: 'Admin Test' },
    action: "Validation d'évaluation",
    module: 'Évaluation',
    objet: 'CASA-2026-000001',
    ancienne_valeur: null,
    nouvelle_valeur: '64.2/65',
    motif: 'SECRET INTERNE — score détaillé',
    resultat: 'Succès',
    ...over,
  }
}

function renderScreen() {
  return render(
    <MemoryRouter>
      <Audit />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuth.mockReturnValue({ user: { profil: { prenom: 'Admin', nom: 'Test' } }, role: 'administrateur', logout: vi.fn() })
})
afterEach(() => vi.restoreAllMocks())

describe('Audit — la zone 🔴 est affichée LÉGITIMEMENT (outil de supervision)', () => {
  it("affiche ancienne_valeur/nouvelle_valeur/motif tels quels — c'est le travail de l'admin", async () => {
    apiClient.get.mockResolvedValueOnce({ data: [ligne()], meta: { current_page: 1, last_page: 1, total: 1 } })
    renderScreen()

    expect(await screen.findByText('64.2/65')).toBeInTheDocument()
    expect(screen.getByText('SECRET INTERNE — score détaillé')).toBeInTheDocument()
  })
})

describe('Audit — la recherche reste BORNÉE côté serveur, aucun filtre client sur le 🔴', () => {
  it('taper dans le champ recherche envoie ?recherche=, PAS de filtrage local des lignes reçues', async () => {
    // Le serveur renvoie déjà 0 résultat pour cette recherche (bornée à action+objet
    // côté backend, prouvé par AuditConsultationTest) : ce composant ne doit PAS
    // essayer de "retrouver" la ligne en filtrant lui-même sur motif/nouvelle_valeur.
    apiClient.get.mockResolvedValueOnce({ data: [ligne()], meta: { current_page: 1, last_page: 1, total: 1 } })
    apiClient.get.mockResolvedValueOnce({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
    renderScreen()
    await screen.findByText('64.2/65')

    // Un seul changement (pas `user.type` caractère par caractère, qui déclenche
    // une requête par frappe) : ce qui compte ici est la requête finale.
    fireEvent.change(screen.getByPlaceholderText('Rechercher une action, un objet…'), { target: { value: 'SECRET' } })

    await waitFor(() => {
      const called = apiClient.get.mock.calls.some(([path]) => decodeURIComponent(path) === '/admin/audit?recherche=SECRET')
      expect(called).toBe(true)
    })
    // Le composant affiche ce que le serveur a renvoyé (0 ligne) — il ne "retrouve"
    // pas la ligne motif en filtrant lui-même le résultat précédent.
    expect(await screen.findByText(/aucune entrée pour ce filtre/i)).toBeInTheDocument()
  })

  it('le champ "auteur" est SÉPARÉ de la recherche — envoyé à ?auteur=, jamais fusionné', async () => {
    apiClient.get.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
    renderScreen()
    await waitFor(() => expect(apiClient.get).toHaveBeenCalledTimes(1))

    const user = userEvent.setup()
    await user.type(screen.getByPlaceholderText('Auteur (e-mail exact)'), 'admin@casa-demo.ci')

    await waitFor(() => {
      const called = apiClient.get.mock.calls.some(([path]) => decodeURIComponent(path) === '/admin/audit?auteur=admin@casa-demo.ci')
      expect(called).toBe(true)
    })
    // Jamais fusionné dans `recherche` (pas de `?recherche=admin@casa-demo.ci`).
    expect(apiClient.get.mock.calls.some(([path]) => decodeURIComponent(path).includes('recherche=admin'))).toBe(false)
  })

  it('le placeholder ne promet PAS de chercher un auteur (honnêteté vs la maquette)', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
    renderScreen()
    await waitFor(() => expect(apiClient.get).toHaveBeenCalled())

    expect(screen.queryByPlaceholderText(/rechercher un auteur/i)).not.toBeInTheDocument()
    expect(screen.getByPlaceholderText('Rechercher une action, un objet…')).toBeInTheDocument()
  })

  it('filtre module -> ?module=, filtre dates -> ?date_debut=/?date_fin=', async () => {
    apiClient.get.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
    renderScreen()
    await waitFor(() => expect(apiClient.get).toHaveBeenCalledTimes(1))

    const user = userEvent.setup()
    await user.selectOptions(screen.getByDisplayValue('Tous les modules'), 'Filières')
    await waitFor(() => {
      const called = apiClient.get.mock.calls.some(([path]) => decodeURIComponent(path) === '/admin/audit?module=Filières')
      expect(called).toBe(true)
    })

    await user.type(screen.getByLabelText('Date de début'), '2026-06-01')
    await waitFor(() => {
      const called = apiClient.get.mock.calls.some(([path]) => path.includes('date_debut=2026-06-01'))
      expect(called).toBe(true)
    })
  })
})
