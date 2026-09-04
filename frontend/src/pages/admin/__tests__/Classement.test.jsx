import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { Classement } from '../Classement.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

const CAMPAGNES = [{ id: 'camp-1', nom: 'Cohorte 1 — 2026', statut: 'cloturee', date_ouverture: '2026-05-01', date_cloture: '2026-06-30', places_totales: 24 }]

function ligne(over = {}) {
  return {
    candidature_id: 'c-1',
    numero_dossier: 'CASA-2026-000001',
    rang: 1,
    decision: 'retenu',
    motif_interne: null,
    motif_communicable: null,
    non_eligible: false,
    candidat: { prenom: 'Awa', nom: 'Konan', sexe: 'F', ville_residence: 'Abidjan - Cocody' },
    score_dossier: '60.0',
    score_entretien: '28.0',
    score_final: '88.0',
    departage: { mixite_f: true, vulnerabilite: 2, experience_secteur: true, mo04: 4 },
    ...over,
  }
}

function classementData(over = {}) {
  return {
    campagne: { id: 'camp-1', nom: 'Cohorte 1 — 2026' },
    version_algorithme: 1,
    liste_attente_taille: 8,
    calcule: true,
    publie: false,
    publiee_le: null,
    publiee_par: null,
    filieres: [
      {
        filiere: { code: 'cuisine', nom: 'Agent de cuisine' },
        quota: 1,
        retenus: 1,
        liste_attente: 0,
        non_retenus: 0,
        lignes: [ligne()],
      },
      {
        filiere: { code: 'restaurant-bar', nom: 'Agent de restaurant-bar' },
        quota: 1,
        retenus: 0,
        liste_attente: 0,
        non_retenus: 0,
        lignes: [],
      },
    ],
    ...over,
  }
}

function mockGet(data) {
  apiClient.get.mockImplementation((path) => {
    if (path === '/admin/campagnes') return Promise.resolve({ data: CAMPAGNES })
    if (path === '/admin/campagnes/camp-1/classement') return Promise.resolve({ data })
    return Promise.resolve({ data: [] })
  })
}

function renderScreen(id = 'camp-1') {
  return render(
    <MemoryRouter initialEntries={[`/admin/classement/${id}`]}>
      <Routes>
        <Route path="/admin/classement/:campagneId" element={<Classement />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuth.mockReturnValue({ user: { profil: { prenom: 'Admin', nom: 'Test' } }, role: 'administrateur', logout: vi.fn() })
})
afterEach(() => vi.restoreAllMocks())

describe('Classement — score final/rang/départage viennent TOUJOURS de la réponse API', () => {
  it("affiche le classement de la filière active, tel que renvoyé par l'API", async () => {
    mockGet(classementData())
    renderScreen()

    expect(await screen.findByText('Awa Konan')).toBeInTheDocument()
    expect(screen.getByText('88.0 / 100')).toBeInTheDocument()
    expect(screen.getByText(/Dossier 60\.0 · Entretien 28\.0/)).toBeInTheDocument()
    expect(screen.getByText('Retenu')).toBeInTheDocument()
    // Départage — visible EN CLAIR (pas au survol), Étape 1 Q2.
    expect(screen.getByText('Vulnérabilité 2')).toBeInTheDocument()
  })

  it('changer de tab filière affiche les lignes déjà chargées, sans nouvel appel réseau', async () => {
    mockGet(classementData())
    renderScreen()
    await screen.findByText('Awa Konan')
    const getCallsAvant = apiClient.get.mock.calls.length

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Agent de restaurant-bar' }))

    expect(await screen.findByText('Aucun candidat classé pour cette filière')).toBeInTheDocument()
    expect(apiClient.get.mock.calls.length).toBe(getCallsAvant) // aucun refetch au changement d'onglet
  })
})

describe('Classement — calcul idempotent tant que non publié', () => {
  it('non calculé -> empty-state + bouton "Calculer" ; après calcul -> classement affiché, bouton devient "Recalculer"', async () => {
    mockGet(classementData({ calcule: false, filieres: [] }))
    renderScreen()

    expect(await screen.findByText('Aucun classement calculé')).toBeInTheDocument()
    const btn = screen.getByRole('button', { name: 'Calculer le classement' })

    apiClient.post.mockResolvedValueOnce({ data: classementData() })
    const user = userEvent.setup()
    await user.click(btn)

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith('/admin/campagnes/camp-1/classement'))
    expect(await screen.findByText('Awa Konan')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Recalculer le classement' })).toBeInTheDocument()
  })

  it('bouton "Publier" désactivé tant que le classement n’est pas calculé', async () => {
    mockGet(classementData({ calcule: false, filieres: [] }))
    renderScreen()
    await screen.findByText('Aucun classement calculé')
    expect(screen.getByRole('button', { name: /publier les résultats/i })).toBeDisabled()
  })
})

describe('Classement — distinction motif interne (🔴) / communicable (🟡)', () => {
  it("le bouton Motif ouvre un modal aux DEUX libellés distincts, PUT envoie les deux champs", async () => {
    mockGet(classementData({
      filieres: [{
        filiere: { code: 'cuisine', nom: 'Agent de cuisine' },
        quota: 1, retenus: 0, liste_attente: 1, non_retenus: 0,
        lignes: [ligne({ decision: 'liste_attente' })],
      }],
    }))
    apiClient.put.mockResolvedValueOnce({
      data: classementData({
        filieres: [{
          filiere: { code: 'cuisine', nom: 'Agent de cuisine' },
          quota: 1, retenus: 0, liste_attente: 1, non_retenus: 0,
          lignes: [ligne({ decision: 'liste_attente', motif_interne: 'Note interne', motif_communicable: 'Message au candidat' })],
        }],
      }),
    })
    renderScreen()
    await screen.findByText('Awa Konan')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Motif' }))

    const dialog = screen.getByRole('dialog', { name: 'Motif de la décision' })
    expect(within(dialog).getByText(/🔴 Motif interne — confidentiel/)).toBeInTheDocument()
    expect(within(dialog).getByText(/🟡 Motif communicable — visible par le candidat après publication/)).toBeInTheDocument()

    await user.type(screen.getByPlaceholderText('Note interne, jamais visible du candidat...'), 'Note interne')
    await user.type(screen.getByPlaceholderText('Laisser vide pour le message générique par défaut...'), 'Message au candidat')
    await user.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(apiClient.put).toHaveBeenCalledWith('/admin/candidatures/c-1/decision/motifs', {
      motif_interne: 'Note interne',
      motif_communicable: 'Message au candidat',
    }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('bouton Motif : absent sur "retenu", présent sur "liste_attente" et "non_retenu"', async () => {
    mockGet(classementData({
      filieres: [{
        filiere: { code: 'cuisine', nom: 'Agent de cuisine' },
        quota: 1, retenus: 1, liste_attente: 1, non_retenus: 1,
        lignes: [
          ligne({ candidature_id: 'c-retenu', decision: 'retenu' }),
          ligne({ candidature_id: 'c-attente', decision: 'liste_attente', numero_dossier: 'CASA-2026-000002' }),
          ligne({ candidature_id: 'c-non-retenu', decision: 'non_retenu', numero_dossier: 'CASA-2026-000003' }),
        ],
      }],
    }))
    renderScreen()
    await screen.findAllByText('Awa Konan')

    expect(screen.getAllByRole('button', { name: 'Motif' })).toHaveLength(2)
  })
})

describe('Classement — publication : friction délibérée + irréversibilité', () => {
  it('publier exige de taper le nom EXACT de la campagne — bouton désactivé sinon', async () => {
    mockGet(classementData())
    renderScreen()
    await screen.findByText('Awa Konan')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /publier les résultats/i }))

    const dialog = screen.getByRole('dialog', { name: 'Publier les résultats' })
    const confirmBtn = within(dialog).getByRole('button', { name: 'Publier définitivement' })
    expect(confirmBtn).toBeDisabled()

    await user.type(within(dialog).getByRole('textbox'), 'Mauvais nom')
    expect(confirmBtn).toBeDisabled()

    await user.clear(within(dialog).getByRole('textbox'))
    await user.type(within(dialog).getByRole('textbox'), 'Cohorte 1 — 2026')
    expect(confirmBtn).toBeEnabled()
  })

  it('publication confirmée -> POST publier -> re-GET classement -> état "déjà publié", actions retirées', async () => {
    mockGet(classementData())
    apiClient.post.mockResolvedValueOnce({ data: { campagne: { id: 'camp-1', nom: 'Cohorte 1 — 2026' }, publiee_le: '2026-06-15T10:00:00Z', publiee_par: { prenom: 'Admin', nom: 'Test' }, candidats_avec_decision: 1 } })
    renderScreen()
    await screen.findByText('Awa Konan')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /publier les résultats/i }))
    const dialog = screen.getByRole('dialog', { name: 'Publier les résultats' })
    await user.type(within(dialog).getByRole('textbox'), 'Cohorte 1 — 2026')

    // Le second GET (après le POST) reflète l'état publié.
    mockGet(classementData({ publie: true, publiee_le: '2026-06-15T10:00:00Z', publiee_par: { prenom: 'Admin', nom: 'Test' } }))
    await user.click(within(dialog).getByRole('button', { name: 'Publier définitivement' }))

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith('/admin/campagnes/camp-1/publier'))
    expect(await screen.findByText('🔒 RÉSULTATS PUBLIÉS')).toBeInTheDocument()
    expect(screen.getByText(/publiés le 15 juin 2026 par admin test/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /calculer le classement/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /publier les résultats/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Motif' })).not.toBeInTheDocument()
  })

  it('422 (aucune décision) au clic sur Publier -> message affiché verbatim', async () => {
    mockGet(classementData())
    apiClient.post.mockRejectedValueOnce(
      new ApiError('http', { status: 422, message: 'Aucune décision : calculez le classement (POST /classement) avant de publier.' }),
    )
    renderScreen()
    await screen.findByText('Awa Konan')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /publier les résultats/i }))
    const dialog = screen.getByRole('dialog', { name: 'Publier les résultats' })
    await user.type(within(dialog).getByRole('textbox'), 'Cohorte 1 — 2026')
    await user.click(within(dialog).getByRole('button', { name: 'Publier définitivement' }))

    expect(await screen.findByText(/calculez le classement.*avant de publier/i)).toBeInTheDocument()
  })
})

describe('Classement — état « déjà publié » : consultation stricte', () => {
  it('publie:true -> aucune action (Calculer/Publier/Motif), bandeau verrouillé, classement toujours visible', async () => {
    mockGet(classementData({
      publie: true,
      publiee_le: '2026-06-15T10:00:00Z',
      publiee_par: { prenom: 'Admin', nom: 'Test' },
      filieres: [{
        filiere: { code: 'cuisine', nom: 'Agent de cuisine' },
        quota: 1, retenus: 0, liste_attente: 1, non_retenus: 0,
        lignes: [ligne({ decision: 'liste_attente' })],
      }],
    }))
    renderScreen()

    expect(await screen.findByText('🔒 RÉSULTATS PUBLIÉS')).toBeInTheDocument()
    expect(screen.getByText('Awa Konan')).toBeInTheDocument() // consultation : toujours visible
    expect(screen.queryByRole('button', { name: /calculer/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /publier/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Motif' })).not.toBeInTheDocument()
  })
})
