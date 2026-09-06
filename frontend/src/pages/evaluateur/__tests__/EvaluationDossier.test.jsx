import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { EvaluationDossier } from '../EvaluationDossier.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn(), put: vi.fn(), post: vi.fn() } }))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

function dossier(over = {}) {
  return {
    id: 'c-1',
    numero_dossier: 'CASA-2026-000001',
    statut_interne: 'en_instruction',
    statut_eligibilite_interne: 'eligible',
    dossier_verrouille: false,
    filiere: { id: 'f1', code: 'cuisine', nom: 'Agent de cuisine' },
    candidat: { id: 'cand-1', prenom: 'Awa', nom: 'Konan', ville_residence: 'Abidjan - Cocody' },
    reponses: { mo04_lettre_motivation: 'Je souhaite intégrer ce programme.' },
    experiences: [],
    pieces_dossier: [],
    verification: { nationalite_confirmee: true, diplome_verifie: 'bepc', verifie_le: '2026-06-10T09:00:00Z', verifie_par: null },
    criteres_eliminatoires: [],
    ...over,
  }
}

function evaluationApercu(over = {}) {
  return {
    verrouille: false,
    source: 'apercu',
    grille: { version: 1, label: 'Grille 2026' },
    mo04_note_etoiles: null,
    commentaire_evaluateur: null,
    score_total: '18.5',
    volet_max: 65,
    rubriques: [
      { code: 'scolaire', label: 'Scolaire', score_obtenu: '6.0000', max: 15 },
      { code: 'socioEco', label: 'Socio-éco', score_obtenu: '3.0000', max: 10 },
      { code: 'experience', label: 'Expérience', score_obtenu: '2.0000', max: 10 },
      { code: 'langues', label: 'Langues', score_obtenu: '4.5000', max: 10 },
      { code: 'motivation', label: 'Motivation', score_obtenu: '0.0000', max: 15 },
      { code: 'disponibilite', label: 'Disponibilité', score_obtenu: '3.0000', max: 5 },
    ],
    valide_le: null,
    valide_par: null,
    ...over,
  }
}

function evaluationSnapshot(over = {}) {
  return evaluationApercu({
    verrouille: true,
    source: 'snapshot',
    mo04_note_etoiles: 4,
    score_total: '52.0',
    valide_le: '2026-06-15T10:00:00Z',
    valide_par: { id: 'm1', prenom: 'Solange', nom: "N'Dri" },
    ...over,
  })
}

function mockGet(dossierData, evaluationData) {
  apiClient.get.mockImplementation((path) => {
    if (path.endsWith('/evaluation')) return Promise.resolve({ data: evaluationData })
    return Promise.resolve({ data: dossierData })
  })
}

function renderScreen(id = 'c-1') {
  return render(
    <MemoryRouter initialEntries={[`/evaluateur/candidatures/${id}/evaluation`]}>
      <Routes>
        <Route path="/evaluateur/candidatures/:id/evaluation" element={<EvaluationDossier />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuth.mockReturnValue({ user: { profil: { prenom: 'Solange', nom: "N'Dri" } }, role: 'evaluateur', logout: vi.fn() })
})
afterEach(() => vi.restoreAllMocks())

describe('EvaluationDossier — le score AFFICHÉ vient toujours de la réponse API', () => {
  it("l'aperçu (total, max, détail par rubrique) est celui du GET, rien de calculé", async () => {
    mockGet(dossier(), evaluationApercu())
    renderScreen()

    expect(await screen.findByText('18.5')).toBeInTheDocument()
    expect(screen.getByText('/ 65')).toBeInTheDocument()
    expect(screen.getByText('Scolaire')).toBeInTheDocument()
    expect(screen.getByText('6.0/15')).toBeInTheDocument()
    expect(screen.getByText('Je souhaite intégrer ce programme.')).toBeInTheDocument()
  })

  it('saisie étoiles + commentaire -> PUT -> le nouvel aperçu renvoyé par le PUT est affiché', async () => {
    mockGet(dossier(), evaluationApercu())
    apiClient.put.mockResolvedValueOnce({
      data: evaluationApercu({ mo04_note_etoiles: 3, commentaire_evaluateur: 'Motivation sincère.', score_total: '25.5' }),
    })
    renderScreen()
    await screen.findByText('18.5')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: '3 étoiles' }))
    await user.type(screen.getByPlaceholderText(/observations de l'évaluateur/i), 'Motivation sincère.')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(apiClient.put).toHaveBeenCalledTimes(1))
    expect(apiClient.put).toHaveBeenCalledWith('/evaluateur/candidatures/c-1/evaluation', {
      mo04_note_etoiles: 3,
      commentaire_evaluateur: 'Motivation sincère.',
    })
    expect(await screen.findByText('25.5')).toBeInTheDocument()
    expect(screen.getByText('Brouillon enregistré.')).toBeInTheDocument()
  })
})

describe('EvaluationDossier — verrouillage visuel RÉEL après validation (ADR-04)', () => {
  it('valider -> fieldset nativement désactivé, boutons remplacés par la bannière, snapshot affiché', async () => {
    mockGet(dossier(), evaluationApercu({ mo04_note_etoiles: 4 }))
    apiClient.post.mockResolvedValueOnce({ data: evaluationSnapshot() })
    renderScreen()
    await screen.findByText('18.5')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Valider définitivement' }))
    await user.click(screen.getByRole('button', { name: 'Valider' }))

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledTimes(1))
    expect(apiClient.post).toHaveBeenCalledWith('/evaluateur/candidatures/c-1/evaluation/validation')

    expect(await screen.findByText('🔒 ÉVALUATION VALIDÉE')).toBeInTheDocument()
    expect(screen.getByText('52.0')).toBeInTheDocument()
    // Le score affiché est le SNAPSHOT figé renvoyé par la validation, pas un recalcul.
    expect(screen.queryByRole('button', { name: 'Enregistrer' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Valider définitivement' })).not.toBeInTheDocument()

    // Tentative d'édition après verrouillage : les champs sont NATIVEMENT désactivés (fieldset), pas juste grisés en CSS.
    expect(screen.getByRole('button', { name: '3 étoiles' })).toBeDisabled()
    expect(screen.getByPlaceholderText(/observations de l'évaluateur/i)).toHaveAttribute('readonly')

    // Le lien vers l'entretien est proposé une fois le dossier verrouillé.
    expect(screen.getByRole('link', { name: /entretien/i })).toBeInTheDocument()
  })

  it('422 serveur à la validation (précondition rejouée) -> message affiché, écran reste déverrouillé', async () => {
    mockGet(dossier(), evaluationApercu({ mo04_note_etoiles: 4 }))
    apiClient.post.mockRejectedValueOnce(
      new ApiError('validation', { status: 422, message: 'La note de motivation (MO.04) doit être saisie avant la validation définitive.' }),
    )
    renderScreen()
    await screen.findByText('18.5')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Valider définitivement' }))
    await user.click(screen.getByRole('button', { name: 'Valider' }))

    expect(await screen.findByText(/la note de motivation \(mo\.04\) doit être saisie/i)).toBeInTheDocument()
    expect(screen.queryByText('🔒 ÉVALUATION VALIDÉE')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Valider définitivement' })).toBeInTheDocument()
  })
})

describe('EvaluationDossier — préconditions reflétées côté UI', () => {
  it('vérification incomplète -> "Valider" désactivé + message explicite', async () => {
    mockGet(dossier({ verification: { nationalite_confirmee: true, diplome_verifie: null, verifie_le: null, verifie_par: null } }), evaluationApercu({ mo04_note_etoiles: 4 }))
    renderScreen()
    await screen.findByText('18.5')

    expect(screen.getByText(/la vérification du dossier.*doit être complétée/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Valider définitivement' })).toBeDisabled()
  })

  it('note MO.04 absente -> "Valider" désactivé (précondition backend miroir)', async () => {
    mockGet(dossier(), evaluationApercu({ mo04_note_etoiles: null }))
    renderScreen()
    await screen.findByText('18.5')
    expect(screen.getByRole('button', { name: 'Valider définitivement' })).toBeDisabled()
  })

  it("dossier non « en_instruction » -> formulaire non modifiable (miroir du 409 backend)", async () => {
    mockGet(dossier({ statut_interne: 'soumis' }), evaluationApercu())
    renderScreen()
    await screen.findByText('18.5')
    expect(screen.getByText(/n'est pas \(ou plus\) « en cours d'instruction »/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Enregistrer' })).not.toBeInTheDocument()
  })
})

describe('EvaluationDossier — correction exceptionnelle (Lot 8d-3, administrateur uniquement)', () => {
  it('précondition : le bouton n’apparaît que verrouillé + administrateur', async () => {
    mockGet(dossier(), evaluationApercu({ mo04_note_etoiles: 4 })) // non verrouillé
    renderScreen()
    await screen.findByText('18.5')
    expect(screen.queryByRole('button', { name: /correction exceptionnelle/i })).not.toBeInTheDocument()
  })

  it('évaluateur (non admin) : même verrouillée, aucun bouton de correction', async () => {
    mockGet(dossier(), evaluationSnapshot())
    renderScreen()
    await screen.findByText('🔒 ÉVALUATION VALIDÉE')
    expect(screen.queryByRole('button', { name: /correction exceptionnelle/i })).not.toBeInTheDocument()
  })

  it('admin + verrouillée -> bannière "vous rouvrez une évaluation validée", motif obligatoire, POST puis re-GET (dossier + évaluation)', async () => {
    useAuth.mockReturnValue({ user: { profil: { prenom: 'Admin', nom: 'Test' } }, role: 'administrateur', logout: vi.fn() })
    mockGet(dossier(), evaluationSnapshot())
    renderScreen()
    await screen.findByText('🔒 ÉVALUATION VALIDÉE')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /correction exceptionnelle/i }))

    const dialog = screen.getByRole('dialog', { name: 'Correction exceptionnelle — dossier' })
    expect(within(dialog).getByText(/vous rouvrez une évaluation validée/i)).toBeInTheDocument()

    // Motif obligatoire : le bouton de confirmation reste désactivé tant que < 3 caractères.
    const confirmBtn = within(dialog).getByRole('button', { name: 'Enregistrer la correction' })
    expect(confirmBtn).toBeDisabled()
    await user.type(within(dialog).getByLabelText(/motif de la correction/i), 'ok')
    expect(confirmBtn).toBeDisabled()
    await user.type(within(dialog).getByLabelText(/motif de la correction/i), ' diplôme réexaminé')
    expect(confirmBtn).toBeEnabled()

    apiClient.post.mockResolvedValueOnce({
      data: { numero_dossier: 'CASA-2026-000001', score_dossier: '48.0', statut_eligibilite_interne: 'eligible', champs_modifies: ['mo04_note_etoiles'] },
    })
    // Après le POST, l'écran recharge dossier + évaluation via `reload()` (forme différente de la réponse POST).
    mockGet(dossier(), evaluationSnapshot({ mo04_note_etoiles: 2, score_total: '48.0' }))
    await user.click(confirmBtn)

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith(
      '/admin/candidatures/c-1/correction/dossier',
      expect.objectContaining({ motif: expect.stringContaining('diplôme réexaminé') }),
    ))
    expect(await screen.findByText('48.0')).toBeInTheDocument() // le nouveau snapshot rechargé, pas la réponse POST injectée telle quelle
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('409 (campagne déjà publiée) -> message backend affiché verbatim, le modal reste ouvert', async () => {
    useAuth.mockReturnValue({ user: { profil: { prenom: 'Admin', nom: 'Test' } }, role: 'administrateur', logout: vi.fn() })
    mockGet(dossier(), evaluationSnapshot())
    apiClient.post.mockRejectedValueOnce(
      new ApiError('http', { status: 409, message: 'Les résultats de cette campagne sont déjà publiés : plus aucune correction possible.' }),
    )
    renderScreen()
    await screen.findByText('🔒 ÉVALUATION VALIDÉE')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /correction exceptionnelle/i }))
    const dialog = screen.getByRole('dialog', { name: 'Correction exceptionnelle — dossier' })
    await user.type(within(dialog).getByLabelText(/motif de la correction/i), 'Motif suffisant')
    await user.click(within(dialog).getByRole('button', { name: 'Enregistrer la correction' }))

    expect(await screen.findByText(/déjà publiés.*plus aucune correction possible/i)).toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: 'Correction exceptionnelle — dossier' })).toBeInTheDocument()
  })
})
