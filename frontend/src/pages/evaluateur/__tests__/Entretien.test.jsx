import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { Entretien } from '../Entretien.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn(), put: vi.fn(), post: vi.fn() } }))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

function dossier(over = {}) {
  return {
    id: 'c-1',
    numero_dossier: 'CASA-2026-000001',
    statut_interne: 'evalue',
    statut_eligibilite_interne: 'eligible',
    dossier_verrouille: true,
    filiere: { id: 'f1', code: 'cuisine', nom: 'Agent de cuisine' },
    candidat: { id: 'cand-1', prenom: 'Awa', nom: 'Konan', ville_residence: 'Abidjan - Cocody' },
    reponses: {},
    experiences: [],
    pieces_dossier: [],
    verification: { nationalite_confirmee: true, diplome_verifie: 'bepc', verifie_le: null, verifie_par: null },
    criteres_eliminatoires: [],
    ...over,
  }
}

const RUBRIQUES = [
  { code: 'presentation', label: 'Présentation', score_obtenu: '0.0', max: 10 },
  { code: 'motivation_orale', label: 'Motivation orale', score_obtenu: '0.0', max: 15 },
  { code: 'projet', label: 'Projet professionnel', score_obtenu: '0.0', max: 10 },
]

const SOUS_NOTES = [
  { code: 'PR.01', rubrique_code: 'presentation', label: 'Clarté de la présentation', points_attribues: '0.0', max: 5 },
  { code: 'PR.02', rubrique_code: 'presentation', label: 'Aisance à l’oral', points_attribues: '0.0', max: 5 },
  { code: 'MO.01', rubrique_code: 'motivation_orale', label: 'Conviction', points_attribues: '0.0', max: 8 },
  { code: 'MO.02', rubrique_code: 'motivation_orale', label: 'Connaissance du métier', points_attribues: '0.0', max: 7 },
  { code: 'PJ.01', rubrique_code: 'projet', label: 'Cohérence du projet', points_attribues: '0.0', max: 10 },
]

function entretienApercu(over = {}) {
  return {
    statut: 'realise',
    verrouille: false,
    source: 'apercu',
    date: '2026-07-06',
    heure: '09:00',
    lieu: 'Le Plateau',
    presence: null,
    observation: null,
    evaluateur: { id: 'm1', prenom: 'Solange', nom: "N'Dri" },
    score_total: '0.0',
    volet_max: 35,
    grille: { version: 1, label: 'Grille 2026' },
    rubriques: RUBRIQUES,
    sous_notes: SOUS_NOTES,
    valide_le: null,
    valide_par: null,
    ...over,
  }
}

function entretienSnapshot(over = {}) {
  return entretienApercu({
    statut: 'valide',
    verrouille: true,
    source: 'snapshot',
    presence: 'present',
    score_total: '28.0',
    valide_le: '2026-07-06T11:00:00Z',
    valide_par: { id: 'm1', prenom: 'Solange', nom: "N'Dri" },
    ...over,
  })
}

function mockGet(dossierData, entretienState) {
  apiClient.get.mockImplementation((path) => {
    if (path.endsWith('/entretien')) {
      return Promise.resolve({ data: { dossier_verrouille: dossierData.dossier_verrouille, entretien: entretienState } })
    }
    return Promise.resolve({ data: dossierData })
  })
}

function renderScreen(id = 'c-1') {
  return render(
    <MemoryRouter initialEntries={[`/evaluateur/candidatures/${id}/entretien`]}>
      <Routes>
        <Route path="/evaluateur/candidatures/:id/entretien" element={<Entretien />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuth.mockReturnValue({ user: { profil: { prenom: 'Solange', nom: "N'Dri" } }, role: 'evaluateur', logout: vi.fn() })
})
afterEach(() => vi.restoreAllMocks())

describe('Entretien — précondition : dossier verrouillé (Lot 4b)', () => {
  it('dossier non verrouillé -> empty-state, aucun formulaire, aucun appel /entretien de saisie', async () => {
    mockGet(dossier({ dossier_verrouille: false }), null)
    renderScreen()

    expect(await screen.findByText('Dossier pas encore validé')).toBeInTheDocument()
    expect(screen.getByText(/l'évaluation du dossier doit être validée/i)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: "Aller à l'évaluation du dossier" })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /planifier/i })).not.toBeInTheDocument()
    expect(apiClient.put).not.toHaveBeenCalled()
  })
})

describe('Entretien — planification (crée l’entretien via le même PUT)', () => {
  it('date/heure/lieu -> PUT crée l’entretien, la notation apparaît après', async () => {
    mockGet(dossier(), null)
    apiClient.put.mockResolvedValueOnce({ data: { dossier_verrouille: true, entretien: entretienApercu() } })
    renderScreen()

    expect(await screen.findByLabelText('Date')).toBeInTheDocument()
    const user = userEvent.setup()
    await user.type(screen.getByLabelText('Date'), '2026-07-06')
    await user.type(screen.getByLabelText('Heure'), '09:00')
    await user.selectOptions(screen.getByLabelText('Lieu'), '2 Plateaux Vallons')
    await user.click(screen.getByRole('button', { name: /planifier l'entretien/i }))

    await waitFor(() => expect(apiClient.put).toHaveBeenCalledTimes(1))
    expect(apiClient.put).toHaveBeenCalledWith('/evaluateur/candidatures/c-1/entretien', {
      date: '2026-07-06',
      heure: '09:00',
      lieu: '2 Plateaux Vallons',
    })
    expect(await screen.findByText('Présence')).toBeInTheDocument()
  })
})

describe('Entretien — notation : le score AFFICHÉ vient toujours de la réponse API', () => {
  it("les sous-notes rendues bornent leurs sélecteurs sur `max` fourni par l'API (aucune constante locale)", async () => {
    mockGet(dossier(), entretienApercu())
    renderScreen()

    await screen.findByRole('heading', { name: 'Présentation', level: 3 })
    // PR.01 a max=5 -> 6 boutons (0..5) ; MO.01 a max=8 -> 9 boutons (0..8).
    const pr01Group = screen.getByRole('radiogroup', { name: 'Clarté de la présentation' })
    expect(pr01Group.querySelectorAll('button')).toHaveLength(6)
    const mo01Group = screen.getByRole('radiogroup', { name: 'Conviction' })
    expect(mo01Group.querySelectorAll('button')).toHaveLength(9)
    expect(screen.getByText('0.0')).toBeInTheDocument()
    expect(screen.getByText('/ 35')).toBeInTheDocument()
  })

  it('présence Présent + sous-notes saisies -> PUT avec `notes`, le nouvel aperçu est affiché', async () => {
    mockGet(dossier(), entretienApercu())
    apiClient.put.mockResolvedValueOnce({
      data: {
        dossier_verrouille: true,
        entretien: entretienApercu({ presence: 'present', score_total: '9.0', sous_notes: SOUS_NOTES.map((sn) => (sn.code === 'PR.01' ? { ...sn, points_attribues: '4.0' } : sn)) }),
      },
    })
    renderScreen()
    await screen.findByRole('heading', { name: 'Présentation', level: 3 })

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Présent' }))
    const pr01Group = screen.getByRole('radiogroup', { name: 'Clarté de la présentation' })
    await user.click(within(pr01Group).getByRole('button', { name: '4' }))
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(apiClient.put).toHaveBeenCalledTimes(1))
    const [path, body] = apiClient.put.mock.calls[0]
    expect(path).toBe('/evaluateur/candidatures/c-1/entretien')
    expect(body.presence).toBe('present')
    expect(body.notes['PR.01']).toBe(4)
    expect(await screen.findByText('9.0')).toBeInTheDocument()
  })

  it('présence Absent -> sous-notes désactivées à 0, `notes` jamais envoyé (miroir du 422 backend)', async () => {
    mockGet(dossier(), entretienApercu())
    apiClient.put.mockResolvedValueOnce({ data: { dossier_verrouille: true, entretien: entretienApercu({ presence: 'absent' }) } })
    renderScreen()
    await screen.findByRole('heading', { name: 'Présentation', level: 3 })

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Absent' }))
    expect(screen.getByText(/candidat absent/i)).toBeInTheDocument()
    const pr01Group = screen.getByRole('radiogroup', { name: 'Clarté de la présentation' })
    within(pr01Group).getAllByRole('button').forEach((btn) => expect(btn).toBeDisabled())

    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))
    await waitFor(() => expect(apiClient.put).toHaveBeenCalledTimes(1))
    const [, body] = apiClient.put.mock.calls[0]
    expect(body.presence).toBe('absent')
    expect(body).not.toHaveProperty('notes')
  })

  it('sous-note hors max rejetée par le backend -> 422 affiché tel quel', async () => {
    mockGet(dossier(), entretienApercu())
    apiClient.put.mockRejectedValueOnce(
      new ApiError('validation', { status: 422, message: 'La note de « PR.01 » (7) dépasse son maximum (5).' }),
    )
    renderScreen()
    await screen.findByRole('heading', { name: 'Présentation', level: 3 })

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Présent' }))
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText(/dépasse son maximum/i)).toBeInTheDocument()
  })

  it('présence non renseignée -> "Valider" désactivé (précondition backend miroir)', async () => {
    mockGet(dossier(), entretienApercu())
    renderScreen()
    await screen.findByRole('heading', { name: 'Présentation', level: 3 })
    expect(screen.getByRole('button', { name: 'Valider définitivement' })).toBeDisabled()
  })
})

describe('Entretien — modifier la planification (Lot 15b)', () => {
  it('le bouton apparaît tant que l’entretien n’est pas verrouillé', async () => {
    mockGet(dossier(), entretienApercu())
    renderScreen()
    await screen.findByRole('heading', { name: 'Présentation', level: 3 })

    expect(screen.getByRole('button', { name: /modifier la planification/i })).toBeInTheDocument()
  })

  it('aucun bouton une fois l’entretien verrouillé (même contrainte que le serveur, 409)', async () => {
    mockGet(dossier(), entretienSnapshot())
    renderScreen()
    await screen.findByText('🔒 ENTRETIEN VALIDÉ')

    expect(screen.queryByRole('button', { name: /modifier la planification/i })).not.toBeInTheDocument()
  })

  it('ouvre le formulaire PRÉ-REMPLI avec les valeurs actuelles, PUT envoie les nouvelles, retour à la notation', async () => {
    mockGet(dossier(), entretienApercu({ presence: 'present' }))
    renderScreen()
    await screen.findByRole('heading', { name: 'Présentation', level: 3 })

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /modifier la planification/i }))

    expect(await screen.findByRole('heading', { name: 'Modifier la planification' })).toBeInTheDocument()
    expect(screen.getByLabelText('Date')).toHaveValue('2026-07-06')
    expect(screen.getByLabelText('Heure')).toHaveValue('09:00')
    expect(screen.getByLabelText('Lieu')).toHaveValue('Le Plateau')

    await user.clear(screen.getByLabelText('Date'))
    await user.type(screen.getByLabelText('Date'), '2026-07-10')
    await user.clear(screen.getByLabelText('Heure'))
    await user.type(screen.getByLabelText('Heure'), '15:00')
    await user.selectOptions(screen.getByLabelText('Lieu'), '2 Plateaux Vallons')

    apiClient.put.mockResolvedValueOnce({
      data: {
        dossier_verrouille: true,
        entretien: entretienApercu({ presence: 'present', date: '2026-07-10', heure: '15:00', lieu: '2 Plateaux Vallons' }),
      },
    })
    await user.click(screen.getByRole('button', { name: /enregistrer la nouvelle planification/i }))

    await waitFor(() => expect(apiClient.put).toHaveBeenCalledWith('/evaluateur/candidatures/c-1/entretien', {
      date: '2026-07-10', heure: '15:00', lieu: '2 Plateaux Vallons',
    }))
    // Le PUT de replanification ne renvoie PAS `notes`/`presence` — la notation
    // en cours (présence déjà « present ») n'est jamais touchée par ce flux.
    expect(apiClient.put.mock.calls[0][1]).not.toHaveProperty('presence')
    expect(apiClient.put.mock.calls[0][1]).not.toHaveProperty('notes')

    // Retour automatique à l'écran de notation, header à jour (source = réponse serveur).
    expect(await screen.findByRole('heading', { name: 'Présentation', level: 3 })).toBeInTheDocument()
    expect(screen.getByText(/2 Plateaux Vallons/)).toBeInTheDocument()
  })

  it('« Annuler » revient à la notation sans appeler le serveur', async () => {
    mockGet(dossier(), entretienApercu())
    renderScreen()
    await screen.findByRole('heading', { name: 'Présentation', level: 3 })

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /modifier la planification/i }))
    await screen.findByRole('heading', { name: 'Modifier la planification' })

    await user.click(screen.getByRole('button', { name: 'Annuler' }))

    expect(await screen.findByRole('heading', { name: 'Présentation', level: 3 })).toBeInTheDocument()
    expect(apiClient.put).not.toHaveBeenCalled()
  })
})

describe('Entretien — verrouillage visuel RÉEL après validation (ADR-04)', () => {
  it('valider -> fieldset nativement désactivé, boutons remplacés par la bannière, snapshot affiché', async () => {
    mockGet(dossier(), entretienApercu({ presence: 'present' }))
    apiClient.post.mockResolvedValueOnce({ data: { dossier_verrouille: true, entretien: entretienSnapshot() } })
    renderScreen()
    await screen.findByRole('heading', { name: 'Présentation', level: 3 })

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Valider définitivement' }))
    await user.click(screen.getByRole('button', { name: 'Valider' }))

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledTimes(1))
    expect(apiClient.post).toHaveBeenCalledWith('/evaluateur/candidatures/c-1/entretien/validation')

    expect(await screen.findByText('🔒 ENTRETIEN VALIDÉ')).toBeInTheDocument()
    expect(screen.getByText('28.0')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Enregistrer' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Valider définitivement' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Présent' })).toBeDisabled()
    const pr01Group = screen.getByRole('radiogroup', { name: 'Clarté de la présentation' })
    within(pr01Group).getAllByRole('button').forEach((btn) => expect(btn).toBeDisabled())
  })
})

describe('Entretien — correction exceptionnelle (Lot 8d-3, administrateur uniquement)', () => {
  it('précondition : le bouton n’apparaît que verrouillé + administrateur', async () => {
    mockGet(dossier(), entretienApercu({ presence: 'present' })) // non verrouillé
    renderScreen()
    await screen.findByRole('heading', { name: 'Présentation', level: 3 })
    expect(screen.queryByRole('button', { name: /correction exceptionnelle/i })).not.toBeInTheDocument()
  })

  it('évaluateur (non admin) : même verrouillé, aucun bouton de correction', async () => {
    mockGet(dossier(), entretienSnapshot())
    renderScreen()
    await screen.findByText('🔒 ENTRETIEN VALIDÉ')
    expect(screen.queryByRole('button', { name: /correction exceptionnelle/i })).not.toBeInTheDocument()
  })

  it('admin + verrouillé -> bannière "vous rouvrez un entretien validé", motif obligatoire, POST puis re-GET entretien', async () => {
    useAuth.mockReturnValue({ user: { profil: { prenom: 'Admin', nom: 'Test' } }, role: 'administrateur', logout: vi.fn() })
    mockGet(dossier(), entretienSnapshot())
    renderScreen()
    await screen.findByText('🔒 ENTRETIEN VALIDÉ')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /correction exceptionnelle/i }))

    const dialog = screen.getByRole('dialog', { name: 'Correction exceptionnelle — entretien' })
    expect(within(dialog).getByText(/vous rouvrez un entretien validé/i)).toBeInTheDocument()

    const confirmBtn = within(dialog).getByRole('button', { name: 'Enregistrer la correction' })
    expect(confirmBtn).toBeDisabled() // motif obligatoire, réellement bloquant
    await user.type(within(dialog).getByLabelText(/motif de la correction/i), 'Points recomptés après réécoute')
    expect(confirmBtn).toBeEnabled()

    apiClient.post.mockResolvedValueOnce({ data: { numero_dossier: 'CASA-2026-000001', score_entretien: '30.0', champs_modifies: ['PR.01'] } })
    mockGet(dossier(), entretienSnapshot({ score_total: '30.0' }))
    await user.click(confirmBtn)

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith(
      '/admin/candidatures/c-1/correction/entretien',
      expect.objectContaining({ motif: 'Points recomptés après réécoute' }),
    ))
    expect(await screen.findByText('30.0')).toBeInTheDocument() // le nouveau snapshot rechargé, pas la réponse POST
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('409 (campagne déjà publiée) -> message backend affiché verbatim, le modal reste ouvert', async () => {
    useAuth.mockReturnValue({ user: { profil: { prenom: 'Admin', nom: 'Test' } }, role: 'administrateur', logout: vi.fn() })
    mockGet(dossier(), entretienSnapshot())
    apiClient.post.mockRejectedValueOnce(
      new ApiError('http', { status: 409, message: 'Les résultats de cette campagne sont déjà publiés : plus aucune correction possible.' }),
    )
    renderScreen()
    await screen.findByText('🔒 ENTRETIEN VALIDÉ')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /correction exceptionnelle/i }))
    const dialog = screen.getByRole('dialog', { name: 'Correction exceptionnelle — entretien' })
    await user.type(within(dialog).getByLabelText(/motif de la correction/i), 'Motif suffisant')
    await user.click(within(dialog).getByRole('button', { name: 'Enregistrer la correction' }))

    expect(await screen.findByText(/déjà publiés.*plus aucune correction possible/i)).toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: 'Correction exceptionnelle — entretien' })).toBeInTheDocument()
  })
})
