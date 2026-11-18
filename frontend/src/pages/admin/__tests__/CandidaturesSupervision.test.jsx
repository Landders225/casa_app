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
  { id: 'eval-1', prenom: 'Yann', nom: 'Kacou', poste: "Chargé d'évaluation", actif: true },
  { id: 'eval-2', prenom: 'Aya', nom: 'Bamba', poste: "Chargée d'évaluation", actif: true },
]
// `?tous=1` (Lot 20b) : inclut un évaluateur désactivé, jamais dans `EVALUATEURS` (actifs seuls).
const EVALUATEURS_TOUS = [
  ...EVALUATEURS,
  { id: 'eval-3', prenom: 'Koffi', nom: 'Désactivé', poste: "Chargé d'évaluation", actif: false },
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
    if (path === '/admin/evaluateurs?tous=1') return Promise.resolve({ data: EVALUATEURS_TOUS })
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

describe('CandidaturesSupervision — Affecter/Réattribuer mutuellement exclusifs (correctif de revue)', () => {
  it('dossier NON affecté sélectionné -> Affecter actif, Réattribuer désactivé', async () => {
    mockCandidatures({ candidatures: [candidature({ evaluateur: null })] })
    renderScreen()
    await screen.findByText('CASA-2026-000001')

    const user = userEvent.setup()
    await user.click(screen.getByRole('checkbox', { name: /sélectionner casa-2026-000001/i }))

    expect(screen.getByRole('button', { name: /affecter \(1\)/i })).toBeEnabled()
    expect(screen.getByRole('button', { name: /réattribuer \(1\)/i })).toBeDisabled()
  })

  it('dossier DÉJÀ affecté sélectionné -> Réattribuer actif, Affecter désactivé', async () => {
    mockCandidatures({ candidatures: [candidature({ evaluateur: { id: 'eval-1', prenom: 'Yann', nom: 'Kacou' } })] })
    renderScreen()
    await screen.findByText('CASA-2026-000001')

    const user = userEvent.setup()
    await user.click(screen.getByRole('checkbox', { name: /sélectionner casa-2026-000001/i }))

    expect(screen.getByRole('button', { name: /réattribuer \(1\)/i })).toBeEnabled()
    expect(screen.getByRole('button', { name: /affecter \(1\)/i })).toBeDisabled()
  })
})

describe('CandidaturesSupervision — réattribution de dossiers déjà affectés (Lot 20b)', () => {
  it('le filtre évaluateur affiche aussi les désactivés, avec la mention « désactivé »', async () => {
    mockCandidatures()
    renderScreen()
    await screen.findByText('CASA-2026-000001')

    const filtre = await screen.findByDisplayValue('Tous les évaluateurs')
    expect(within(filtre).getByText('Koffi Désactivé (désactivé)')).toBeInTheDocument()
    expect(within(filtre).getByText('Yann Kacou')).toBeInTheDocument()
  })

  it('sélection -> réattribuer -> bouton bloqué jusqu’à la case cochée -> POST correct, sélection vidée', async () => {
    mockCandidatures({ candidatures: [candidature({ evaluateur: { id: 'eval-1', prenom: 'Yann', nom: 'Kacou' } })] })
    renderScreen()
    await screen.findByText('CASA-2026-000001')

    apiClient.post.mockResolvedValueOnce({ data: { reattribuees: 1, evaluateur: { id: 'eval-2', prenom: 'Aya', nom: 'Bamba' } } })

    const user = userEvent.setup()
    await user.click(screen.getByRole('checkbox', { name: /sélectionner casa-2026-000001/i }))
    await user.click(screen.getByRole('button', { name: /réattribuer \(1\)/i }))

    const dialog = screen.getByRole('dialog', { name: 'Réattribuer' })
    const confirmBtn = within(dialog).getByRole('button', { name: 'Réattribuer' })
    expect(confirmBtn).toBeDisabled()

    await user.selectOptions(within(dialog).getByRole('combobox', { name: /nouvelle affectation/i }), 'eval-2')
    await user.click(within(dialog).getByRole('checkbox', { name: /j'ai compris/i }))
    expect(confirmBtn).toBeEnabled()
    await user.click(confirmBtn)

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith('/admin/reattributions', { evaluateur_id: 'eval-2', candidature_ids: ['c-1'] }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /réattribuer \(/i })).not.toBeInTheDocument()
  })

  it('« remettre dans le lot non affecté » envoie evaluateur_id: null', async () => {
    mockCandidatures({ candidatures: [candidature({ evaluateur: { id: 'eval-1', prenom: 'Yann', nom: 'Kacou' } })] })
    renderScreen()
    await screen.findByText('CASA-2026-000001')

    apiClient.post.mockResolvedValueOnce({ data: { reattribuees: 1, evaluateur: null } })

    const user = userEvent.setup()
    await user.click(screen.getByRole('checkbox', { name: /sélectionner casa-2026-000001/i }))
    await user.click(screen.getByRole('button', { name: /réattribuer \(1\)/i }))

    const dialog = screen.getByRole('dialog', { name: 'Réattribuer' })
    await user.selectOptions(within(dialog).getByRole('combobox', { name: /nouvelle affectation/i }), 'Remettre dans le lot non affecté')
    await user.click(within(dialog).getByRole('checkbox', { name: /j'ai compris/i }))
    await user.click(within(dialog).getByRole('button', { name: 'Réattribuer' }))

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith('/admin/reattributions', { evaluateur_id: null, candidature_ids: ['c-1'] }))
  })

  it('422 atomique (dossier déjà verrouillé) -> message VERBATIM affiché, le modal reste ouvert', async () => {
    mockCandidatures({ candidatures: [candidature({ evaluateur: { id: 'eval-1', prenom: 'Yann', nom: 'Kacou' } })] })
    renderScreen()
    await screen.findByText('CASA-2026-000001')

    apiClient.post.mockRejectedValueOnce(
      new ApiError('validation', {
        status: 422,
        errors: { candidature_ids: ["Dossier déjà verrouillé — impossible de remettre dans le lot non affecté, seule une réattribution vers un autre évaluateur est permise : CASA-2026-000001. Aucune réattribution effectuée."] },
      }),
    )

    const user = userEvent.setup()
    await user.click(screen.getByRole('checkbox', { name: /sélectionner casa-2026-000001/i }))
    await user.click(screen.getByRole('button', { name: /réattribuer \(1\)/i }))
    const dialog = screen.getByRole('dialog', { name: 'Réattribuer' })
    await user.click(within(dialog).getByRole('checkbox', { name: /j'ai compris/i }))
    await user.click(within(dialog).getByRole('button', { name: 'Réattribuer' }))

    // Deux `role="alert"` coexistent dans ce modal (l'avertissement permanent
    // + l'erreur serveur) : on cible l'erreur par son texte, pas par le rôle.
    const alerte = await screen.findByText(/aucune réattribution effectuée/i)
    expect(alerte).toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: 'Réattribuer' })).toBeInTheDocument()
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

/**
 * Cibles par évaluateur (Lot 3, ADR-48) — champ facultatif « nombre de
 * dossiers à recevoir » POUR CETTE OPÉRATION. Aucun calcul de répartition
 * côté client : seule la comparaison « somme saisie / affectables » (blocage
 * de la confirmation) est locale.
 */
const APERCU_CIBLES = {
  nombre_correspondants: 45,
  nombre_affectables: 40,
  ignores: { deja_affectes: 3, non_eligibles: 2, statut_non_affectable: 0 },
  evaluateurs: [
    { id: 'eval-2', prenom: 'Aya', nom: 'Bamba', charge_actuelle: 0, a_recevoir: 10, charge_finale: 10 },
    { id: 'eval-1', prenom: 'Yann', nom: 'Kacou', charge_actuelle: 0, a_recevoir: 30, charge_finale: 30 },
  ],
  non_affectes: 0,
}

describe('CandidaturesSupervision — cibles par évaluateur (Lot 3, ADR-48)', () => {
  it('saisir une cible relance l’aperçu avec `cibles`, puis la confirmation renvoie `repartition_attendue`/`non_affectes_attendu`', async () => {
    mockListeLongue({ apercu: () => Promise.resolve({ data: APERCU_CIBLES }) })
    renderScreen()
    const user = userEvent.setup()
    await passerEnModeTous(user)
    await user.click(screen.getByRole('button', { name: /répartir automatiquement/i }))
    const dialog = screen.getByRole('dialog', { name: 'Répartir automatiquement' })
    await within(dialog).findByText(/seront répartis/i)

    await user.type(within(dialog).getByRole('spinbutton', { name: 'Cible pour Aya Bamba' }), '10')

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith('/admin/affectations/automatique/apercu', {
      criteres: {},
      nombre_correspondants: 45,
      cibles: [{ evaluateur_id: 'eval-2', nombre: 10 }],
    }))
    expect(await within(dialog).findByText(/10 dossiers? ciblés? sur 40 affectables?/i)).toBeInTheDocument()

    await user.click(within(dialog).getByRole('button', { name: 'Répartir 40 dossiers' }))

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith('/admin/affectations/automatique', {
      criteres: {},
      nombre_correspondants: 45,
      nombre_affectables: 40,
      cibles: [{ evaluateur_id: 'eval-2', nombre: 10 }],
      repartition_attendue: [
        { evaluateur_id: 'eval-2', a_recevoir: 10 },
        { evaluateur_id: 'eval-1', a_recevoir: 30 },
      ],
      non_affectes_attendu: 0,
    }))
  })

  it('une somme de cibles dépassant les affectables bloque la confirmation, EN DIRECT, sans appel serveur supplémentaire', async () => {
    mockListeLongue({ apercu: () => Promise.resolve({ data: APERCU_CIBLES }) })
    renderScreen()
    const user = userEvent.setup()
    await passerEnModeTous(user)
    await user.click(screen.getByRole('button', { name: /répartir automatiquement/i }))
    const dialog = screen.getByRole('dialog', { name: 'Répartir automatiquement' })
    await within(dialog).findByText(/seront répartis/i)

    await user.type(within(dialog).getByRole('spinbutton', { name: 'Cible pour Aya Bamba' }), '30')
    await user.type(within(dialog).getByRole('spinbutton', { name: 'Cible pour Yann Kacou' }), '30')

    expect(await within(dialog).findByText(/dépassement/i)).toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: /répartir/i })).toBeDisabled()
  })

  it('décocher un évaluateur cible retire sa cible et désactive son champ', async () => {
    mockListeLongue({ apercu: () => Promise.resolve({ data: APERCU_CIBLES }) })
    renderScreen()
    const user = userEvent.setup()
    await passerEnModeTous(user)
    await user.click(screen.getByRole('button', { name: /répartir automatiquement/i }))
    const dialog = screen.getByRole('dialog', { name: 'Répartir automatiquement' })
    await within(dialog).findByText(/seront répartis/i)

    await user.type(within(dialog).getByRole('spinbutton', { name: 'Cible pour Aya Bamba' }), '10')
    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith('/admin/affectations/automatique/apercu', expect.objectContaining({ cibles: [{ evaluateur_id: 'eval-2', nombre: 10 }] })))

    await user.click(within(dialog).getByRole('checkbox', { name: 'Aya Bamba' }))

    expect(within(dialog).getByRole('spinbutton', { name: 'Cible pour Aya Bamba' })).toBeDisabled()
    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith('/admin/affectations/automatique/apercu', {
      criteres: {},
      nombre_correspondants: 45,
      evaluateur_ids: ['eval-1'],
    }))
  })

  it('taper une cible pendant un aperçu encore en attente (réseau non instantané) ne perd aucun chiffre (correctif de revue)', async () => {
    const resolveurs = []
    mockListeLongue({ apercu: () => new Promise((resolve) => { resolveurs.push(resolve) }) })
    renderScreen()
    const user = userEvent.setup()
    await passerEnModeTous(user)
    await user.click(screen.getByRole('button', { name: /répartir automatiquement/i }))
    const dialog = screen.getByRole('dialog', { name: 'Répartir automatiquement' })
    // L'aperçu d'ouverture est encore EN ATTENTE (réseau lent, jamais résolu
    // ici) : le champ de cible ne doit PAS être désactivé pendant ce temps,
    // sous peine de perdre des frappes/le focus en cours de saisie.
    await waitFor(() => expect(resolveurs.length).toBeGreaterThanOrEqual(1))

    const champCible = within(dialog).getByRole('spinbutton', { name: 'Cible pour Aya Bamba' })
    expect(champCible).toBeEnabled()
    await user.type(champCible, '10')
    expect(champCible).toHaveValue(10)

    // Chaque frappe a relancé un aperçu (toujours en attente) : les résoudre
    // toutes avec le même résultat final — la dernière requête gagne (compteur
    // de requête, correctif de revue précédent) — pour vérifier que « 10 » a
    // bien été envoyé au serveur, pas seulement affiché localement.
    resolveurs.forEach((resolve) => resolve({ data: APERCU_CIBLES }))
    await within(dialog).findByText(/seront répartis/i)

    expect(apiClient.post).toHaveBeenCalledWith('/admin/affectations/automatique/apercu', expect.objectContaining({
      cibles: [{ evaluateur_id: 'eval-2', nombre: 10 }],
    }))
  })

  it('un reliquat non affecté (cibles couvrant tout le monde) affiche un avertissement non bloquant', async () => {
    mockListeLongue({ apercu: () => Promise.resolve({ data: { ...APERCU_CIBLES, non_affectes: 5 } }) })
    renderScreen()
    const user = userEvent.setup()
    await passerEnModeTous(user)
    await user.click(screen.getByRole('button', { name: /répartir automatiquement/i }))
    const dialog = screen.getByRole('dialog', { name: 'Répartir automatiquement' })
    await within(dialog).findByText(/seront répartis/i)

    expect(within(dialog).getByRole('alert')).toHaveTextContent('5 dossiers affectables resteront NON affectés')
    // Correctif de revue : le bouton affiche les dossiers RÉELLEMENT
    // répartis (40 affectables − 5 non affectés), pas les 40 affectables.
    expect(within(dialog).getByRole('button', { name: 'Répartir 35 dossiers' })).toBeEnabled()
  })

  it('un reliquat total (0 dossier réparti) désactive la confirmation', async () => {
    mockListeLongue({ apercu: () => Promise.resolve({ data: { ...APERCU_CIBLES, non_affectes: 40 } }) })
    renderScreen()
    const user = userEvent.setup()
    await passerEnModeTous(user)
    await user.click(screen.getByRole('button', { name: /répartir automatiquement/i }))
    const dialog = screen.getByRole('dialog', { name: 'Répartir automatiquement' })

    expect(await within(dialog).findByRole('button', { name: 'Répartir 0 dossier' })).toBeDisabled()
  })
})
