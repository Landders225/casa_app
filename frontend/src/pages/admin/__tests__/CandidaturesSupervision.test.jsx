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

/**
 * La LISTE des candidatures passe désormais par `POST /admin/candidatures`
 * (ADR-39, pas GET) : seule cette route répond par ce mock sur `post` —
 * `mockResolvedValueOnce`/`mockRejectedValueOnce` posés par un test pour
 * l'affectation/l'élimination (autres chemins `post`) restent prioritaires
 * sur l'appel suivant, cette implémentation par défaut ne les court-circuite
 * pas (FIFO des mocks vi/jest : le « once » est consommé avant de retomber ici).
 */
function mockCandidatures({ candidatures = [candidature()], meta = { current_page: 1, last_page: 1, total: 1 } } = {}) {
  apiClient.post.mockImplementation((path) => {
    if (path === '/admin/candidatures') return Promise.resolve({ data: candidatures, meta })
    return Promise.resolve({ data: [] })
  })
  apiClient.get.mockImplementation((path) => {
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
    mockCandidatures()
    renderScreen()

    expect(await screen.findByText('CASA-2026-000001')).toBeInTheDocument()
    expect(screen.getByText('Awa Konan')).toBeInTheDocument()
    expect(within(screen.getByRole('table')).getByText('Non affecté')).toBeInTheDocument()
  })

  it('changer un filtre relance la requête avec le bon paramètre', async () => {
    mockCandidatures()
    renderScreen()
    await screen.findByText('CASA-2026-000001')

    const user = userEvent.setup()
    await user.selectOptions(screen.getByDisplayValue('Tous les statuts'), 'en_instruction')

    await waitFor(() => {
      const called = apiClient.post.mock.calls.some(
        ([path, body]) => path === '/admin/candidatures' && body.statut_interne === 'en_instruction',
      )
      expect(called).toBe(true)
    })
  })

  it('changer un filtre vide la sélection (aucune action groupée sur un dossier qui disparaît)', async () => {
    mockCandidatures()
    renderScreen()
    await screen.findByText('CASA-2026-000001')

    const user = userEvent.setup()
    await user.click(screen.getByRole('checkbox', { name: /sélectionner casa-2026-000001/i }))
    expect(await screen.findByRole('button', { name: /affecter \(1\)/i })).toBeInTheDocument()

    await user.selectOptions(screen.getByDisplayValue('Tous les statuts'), 'en_instruction')

    await waitFor(() => {
      expect(screen.queryByRole('button', { name: /affecter \(/i })).not.toBeInTheDocument()
    })
  })
})

describe('CandidaturesSupervision — affectation en masse (atomique)', () => {
  it('sélection -> affecter -> succès -> POST correct, sélection vidée, liste rechargée', async () => {
    mockCandidatures()
    const appelsCandidaturesAvant = () => apiClient.post.mock.calls.filter(([p]) => p === '/admin/candidatures').length
    renderScreen()
    await screen.findByText('CASA-2026-000001')
    const listeAvant = appelsCandidaturesAvant()

    apiClient.post.mockResolvedValueOnce({ data: { affectees: 1, evaluateur: { id: 'eval-1', prenom: 'Yann', nom: 'Kacou' } } })

    const user = userEvent.setup()
    await user.click(screen.getByRole('checkbox', { name: /sélectionner casa-2026-000001/i }))
    await user.click(screen.getByRole('button', { name: /affecter \(1\)/i }))

    const dialog = screen.getByRole('dialog', { name: 'Affecter à un évaluateur' })
    await user.selectOptions(dialog.querySelector('select'), 'eval-1')
    await user.click(screen.getByRole('button', { name: 'Affecter' }))

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith('/admin/affectations', { evaluateur_id: 'eval-1', candidature_ids: ['c-1'] }))

    // Le modal se ferme, la sélection est vidée, la liste est rechargée.
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /affecter \(/i })).not.toBeInTheDocument()
    await waitFor(() => expect(appelsCandidaturesAvant()).toBeGreaterThan(listeAvant))
  })

  it('422 atomique -> message VERBATIM affiché, sélection PRÉSERVÉE (le modal reste ouvert)', async () => {
    mockCandidatures()
    renderScreen()
    await screen.findByText('CASA-2026-000001')

    // Posée APRÈS le chargement initial de la liste : la liste et
    // l'affectation partagent désormais `apiClient.post` (ADR-39), un
    // rejet « once » posé avant le rendu serait consommé par la liste.
    apiClient.post.mockRejectedValueOnce(
      new ApiError('validation', {
        status: 422,
        errors: { candidature_ids: ['Non affectable(s) (doit être « soumise » ou « en instruction ») : CASA-2026-000001. Aucune affectation effectuée.'] },
      }),
    )

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
    mockCandidatures({ candidatures: [candidature({ statut_eligibilite_interne: 'non_eligible' })] })
    renderScreen()
    await screen.findByText('CASA-2026-000001')
    expect(screen.getByRole('button', { name: 'Éliminer' })).toBeDisabled()
  })

  it('motif obligatoire (bouton bloqué), succès -> POST mono-cible puis liste rechargée', async () => {
    mockCandidatures()
    renderScreen()
    await screen.findByText('CASA-2026-000001')
    const appelsCandidaturesAvant = apiClient.post.mock.calls.filter(([p]) => p === '/admin/candidatures').length

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Éliminer' }))

    const dialog = screen.getByRole('dialog', { name: 'Éliminer ce dossier ?' })
    const confirmBtn = within(dialog).getByRole('button', { name: 'Éliminer' })
    expect(confirmBtn).toBeDisabled()
    await user.type(within(dialog).getByLabelText(/motif/i), 'Pièce falsifiée')
    expect(confirmBtn).toBeEnabled()

    apiClient.post.mockResolvedValueOnce({ data: { numero_dossier: 'CASA-2026-000001', statut_eligibilite_interne: 'non_eligible' } })
    await user.click(confirmBtn)

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith('/admin/candidatures/c-1/elimination', { motif: 'Pièce falsifiée' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    // Mono-cible (D-6b-4) : un seul candidat visé par le POST, pas d'endpoint bulk.
    await waitFor(() => expect(apiClient.post.mock.calls.filter(([p]) => p === '/admin/candidatures').length).toBeGreaterThan(appelsCandidaturesAvant))
  })

  it('422 backend -> message affiché verbatim, le modal reste ouvert', async () => {
    mockCandidatures()
    renderScreen()
    await screen.findByText('CASA-2026-000001')

    // Posée APRÈS le chargement initial (même raison que le test d'affectation ci-dessus).
    apiClient.post.mockRejectedValueOnce(new ApiError('validation', { status: 422, message: 'Le motif doit contenir au moins 3 caractères.' }))

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Éliminer' }))
    const dialog = screen.getByRole('dialog', { name: 'Éliminer ce dossier ?' })
    await user.type(within(dialog).getByLabelText(/motif/i), 'Motif suffisant')
    await user.click(within(dialog).getByRole('button', { name: 'Éliminer' }))

    expect(await screen.findByText(/le motif doit contenir au moins 3 caractères/i)).toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: 'Éliminer ce dossier ?' })).toBeInTheDocument()
  })
})

/**
 * Sélection « tous les N résultats » + répartition automatique (ADR-45).
 * Aucun identifiant de dossier n'est envoyé : critères + nombres seulement.
 */
const APERCU = {
  nombre_correspondants: 45,
  nombre_affectables: 40,
  ignores: { deja_affectes: 3, non_eligibles: 2, statut_non_affectable: 0 },
  evaluateurs: [
    { id: 'eval-2', prenom: 'Aya', nom: 'Bamba', charge_actuelle: 0, a_recevoir: 20, charge_finale: 20 },
    { id: 'eval-1', prenom: 'Yann', nom: 'Kacou', charge_actuelle: 0, a_recevoir: 20, charge_finale: 20 },
  ],
}

function mockListeLongue({ apercu = () => Promise.resolve({ data: APERCU }), execution } = {}) {
  mockCandidatures({
    candidatures: [candidature(), candidature({ id: 'c-2', numero_dossier: 'CASA-2026-000002' })],
    meta: { current_page: 1, last_page: 3, total: 45 },
  })
  const liste = apiClient.post.getMockImplementation()
  apiClient.post.mockImplementation((path, body) => {
    if (path === '/admin/affectations/automatique/apercu') return apercu(body)
    if (path === '/admin/affectations/automatique') {
      return execution ? execution(body) : Promise.resolve({ data: { ...APERCU, affectees: 40 } })
    }
    return liste(path, body)
  })
}

async function passerEnModeTous(user) {
  await screen.findByText('CASA-2026-000001')
  await user.click(screen.getByRole('checkbox', { name: 'Tout sélectionner' }))
  await user.click(screen.getByRole('button', { name: /sélectionner les 45 dossiers correspondant/i }))
}

const appelsPost = (path) => apiClient.post.mock.calls.filter(([p]) => p === path)

describe('CandidaturesSupervision — sélection de tous les résultats (ADR-45)', () => {
  it("la case d'en-tête coche la page, puis le bandeau propose les N résultats", async () => {
    mockListeLongue()
    renderScreen()
    const user = userEvent.setup()
    await screen.findByText('CASA-2026-000001')

    await user.click(screen.getByRole('checkbox', { name: 'Tout sélectionner' }))
    expect(screen.getByText(/les 2 dossiers de cette page sont sélectionnés/i)).toBeInTheDocument()
    // Mode page : seule l'affectation manuelle est proposée.
    expect(screen.getByRole('button', { name: /affecter \(2\)/i })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /répartir automatiquement/i })).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /sélectionner les 45 dossiers correspondant/i }))

    expect(screen.getByText(/les 45 dossiers correspondant à votre recherche et vos filtres sont sélectionnés/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /répartir automatiquement \(45\)/i })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /affecter \(/i })).not.toBeInTheDocument()
    const ligne = screen.getByRole('checkbox', { name: /sélectionner casa-2026-000001/i })
    expect(ligne).toBeChecked()
    expect(ligne).toBeDisabled()
  })

  it('« Tout désélectionner » quitte le mode et vide la sélection', async () => {
    mockListeLongue()
    renderScreen()
    const user = userEvent.setup()
    await passerEnModeTous(user)

    await user.click(screen.getByRole('button', { name: 'Tout désélectionner' }))

    expect(screen.queryByRole('button', { name: /répartir automatiquement/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /affecter \(/i })).not.toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: /sélectionner casa-2026-000001/i })).not.toBeChecked()
  })

  it('changer un filtre OU de page vide le mode « tous » (ADR-39 inchangé)', async () => {
    mockListeLongue()
    renderScreen()
    const user = userEvent.setup()

    await passerEnModeTous(user)
    await user.selectOptions(screen.getByDisplayValue('Tous les statuts'), 'soumis')
    await waitFor(() => expect(screen.queryByRole('button', { name: /répartir automatiquement/i })).not.toBeInTheDocument())

    await passerEnModeTous(user)
    await user.click(screen.getByRole('button', { name: 'Page suivante' }))
    await waitFor(() => expect(screen.queryByRole('button', { name: /répartir automatiquement/i })).not.toBeInTheDocument())
  })

  it("aperçu (critères figés, aucun id) puis confirmation -> POST avec les nombres de l'aperçu, succès annoncé", async () => {
    mockListeLongue()
    renderScreen()
    const user = userEvent.setup()
    await screen.findByText('CASA-2026-000001')
    await user.selectOptions(screen.getByDisplayValue('Toutes les filières'), 'cuisine')
    await passerEnModeTous(user)
    const listeAvant = appelsPost('/admin/candidatures').length

    await user.click(screen.getByRole('button', { name: /répartir automatiquement \(45\)/i }))
    const dialog = screen.getByRole('dialog', { name: 'Répartir automatiquement' })

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith('/admin/affectations/automatique/apercu', {
      criteres: { filiere: 'cuisine' },
      nombre_correspondants: 45,
    }))
    expect(await within(dialog).findByText(/40 dossiers seront répartis entre 2 évaluateurs : 20 chacun/i)).toBeInTheDocument()
    expect(within(dialog).getByText(/5 ignorés : 3 déjà affecté\(s\), 2 non éligible\(s\)/i)).toBeInTheDocument()

    await user.click(within(dialog).getByRole('button', { name: 'Répartir 40 dossiers' }))

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith('/admin/affectations/automatique', {
      criteres: { filiere: 'cuisine' },
      nombre_correspondants: 45,
      nombre_affectables: 40,
    }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(await screen.findByRole('alert')).toHaveTextContent('40 dossiers répartis entre 2 évaluateurs.')
    expect(screen.queryByRole('button', { name: /répartir automatiquement/i })).not.toBeInTheDocument()
    await waitFor(() => expect(appelsPost('/admin/candidatures').length).toBeGreaterThan(listeAvant))
    for (const [path, body] of apiClient.post.mock.calls) {
      if (path.startsWith('/admin/affectations/automatique')) expect(body).not.toHaveProperty('candidature_ids')
    }
  })

  it("décocher un évaluateur relance l'aperçu avec la restriction", async () => {
    mockListeLongue()
    renderScreen()
    const user = userEvent.setup()
    await passerEnModeTous(user)
    await user.click(screen.getByRole('button', { name: /répartir automatiquement/i }))
    const dialog = screen.getByRole('dialog', { name: 'Répartir automatiquement' })
    await within(dialog).findByText(/seront répartis/i)

    await user.click(within(dialog).getByRole('checkbox', { name: 'Aya Bamba' }))

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith('/admin/affectations/automatique/apercu', {
      criteres: {},
      nombre_correspondants: 45,
      evaluateur_ids: ['eval-1'],
    }))
  })

  it('409 à la confirmation -> message verbatim, modal ouvert, confirmation bloquée jusqu’au recalcul', async () => {
    mockListeLongue({
      execution: () => Promise.reject(new ApiError('http', {
        status: 409,
        message: "Les dossiers affectables ont changé (39 au lieu de 40) : recalculez l'aperçu et reconfirmez.",
        payload: { code: 'liste_modifiee' },
      })),
    })
    renderScreen()
    const user = userEvent.setup()
    await passerEnModeTous(user)
    await user.click(screen.getByRole('button', { name: /répartir automatiquement/i }))
    const dialog = screen.getByRole('dialog', { name: 'Répartir automatiquement' })
    await user.click(await within(dialog).findByRole('button', { name: 'Répartir 40 dossiers' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent('39 au lieu de 40')
    expect(within(dialog).getByRole('button', { name: 'Répartir 40 dossiers' })).toBeDisabled()

    const apercusAvant = appelsPost('/admin/affectations/automatique/apercu').length
    await user.click(within(dialog).getByRole('button', { name: "Recalculer l'aperçu" }))
    await waitFor(() => expect(appelsPost('/admin/affectations/automatique/apercu').length).toBe(apercusAvant + 1))
    await waitFor(() => expect(within(dialog).getByRole('button', { name: 'Répartir 40 dossiers' })).toBeEnabled())
  })

  it("liste modifiée dès l'aperçu -> fermer recharge la liste et vide la sélection", async () => {
    mockListeLongue({
      apercu: () => Promise.reject(new ApiError('http', {
        status: 409,
        message: 'La liste a changé (46 dossiers correspondent au lieu de 45) : rechargez la liste et reconfirmez votre sélection.',
        payload: { code: 'liste_modifiee' },
      })),
    })
    renderScreen()
    const user = userEvent.setup()
    await passerEnModeTous(user)
    const listeAvant = appelsPost('/admin/candidatures').length
    await user.click(screen.getByRole('button', { name: /répartir automatiquement/i }))
    const dialog = screen.getByRole('dialog', { name: 'Répartir automatiquement' })
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('46 dossiers correspondent au lieu de 45')

    await user.click(within(dialog).getByRole('button', { name: 'Annuler' }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /répartir automatiquement/i })).not.toBeInTheDocument()
    await waitFor(() => expect(appelsPost('/admin/candidatures').length).toBeGreaterThan(listeAvant))
  })
})
