import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { useAuth } from '../../../auth/useAuth.js'
import { MaCandidature } from '../MaCandidature.jsx'
import { MESSAGE_NON_RETENU_GENERIQUE } from '../resultatMessages.js'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn() } }))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

/** Tout ce que l'écran candidat ne doit JAMAIS laisser transparaître. */
const TERMES_INTERNES =
  /score|\brang\b|bar[èe]me|bareme|[ée]ligib|eligib|[ée]valuat|evaluat|\bnote\b|\/(35|65|100)|\bpoints?\b|pond[ée]r|non[_ -]?eligible|statut_interne|motif_interne/i

function payload(over = {}) {
  return {
    id: 'c-1',
    numero_dossier: 'CASA-2026-000042',
    statut_public: 'en_cours_de_traitement',
    decision: null,
    motif_communicable: null,
    cqp_confirme: true,
    date_soumission: '2026-06-01T09:00:00+00:00',
    filiere: { id: 'f1', code: 'cuisine', nom: 'Agent de cuisine' },
    campagne: { id: 'k1', nom: 'Cohorte 2026' },
    pieces_dossier: [{ id: '1' }, { id: '2' }, { id: '3' }, { id: '4' }, { id: '5' }, { id: '6' }],
    reponses: {},
    experiences: [],
    classement: [],
    ...over,
  }
}

function renderScreen() {
  return render(
    <MemoryRouter>
      <MaCandidature />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuth.mockReturnValue({
    user: { email: 'aya@example.ci', profil: { prenom: 'Aya', nom: 'T' } },
    role: 'candidat',
    logout: vi.fn(),
  })
})
afterEach(() => vi.restoreAllMocks())

describe('MaCandidature — rendu par statut_public', () => {
  it('404 -> « pas encore de dossier » + CTA wizard', async () => {
    apiClient.get.mockRejectedValueOnce(new ApiError('http', { status: 404 }))
    renderScreen()
    expect(await screen.findByText(/pas encore de dossier de candidature/i)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /démarrer mon dossier/i })).toHaveAttribute('href', '/candidat/candidature')
  })

  it('brouillon -> « pas encore soumis » + CTA reprendre', async () => {
    apiClient.get.mockResolvedValueOnce({ data: payload({ statut_public: 'brouillon', date_soumission: null }) })
    renderScreen()
    expect(await screen.findByText(/n'est pas encore soumis/i)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /reprendre mon dossier/i })).toBeInTheDocument()
  })

  it('en_cours_de_traitement -> phrase neutre unique, AUCUNE info de décision', async () => {
    apiClient.get.mockResolvedValueOnce({ data: payload() })
    const { container } = renderScreen()
    expect(await screen.findByText('Candidature en cours de traitement')).toBeInTheDocument()
    expect(screen.getByText(/le résultat vous sera communiqué à l'issue du processus/i)).toBeInTheDocument()
    // rien qui ressemble à un résultat
    expect(screen.queryByText(/félicitations|retenue|liste d'attente|clôturée/i)).toBeNull()
    expect(container.textContent).not.toMatch(TERMES_INTERNES)
  })

  it('en_cours_de_traitement -> le stepper ne montre aucune étape interne', async () => {
    apiClient.get.mockResolvedValueOnce({ data: payload() })
    renderScreen()
    await screen.findByText('Candidature en cours de traitement')
    for (const interdit of [/entretien/i, /évaluation/i, /éligibilité/i, /instruction/i, /non conforme/i]) {
      expect(screen.queryByText(interdit)).toBeNull()
    }
    expect(screen.getByText('En traitement')).toBeInTheDocument() // l'étape coarse, elle, est là
  })
})

describe('MaCandidature — résultat après publication (decision_publiee)', () => {
  const pub = (decision, motif = null) =>
    payload({ statut_public: 'decision_publiee', decision, motif_communicable: motif })

  it('retenu -> félicitations + filière confirmée', async () => {
    apiClient.get.mockResolvedValueOnce({ data: pub('retenu') })
    renderScreen()
    expect(await screen.findByText(/félicitations, votre candidature est retenue/i)).toBeInTheDocument()
    expect(screen.getByText(/votre place en filière agent de cuisine est confirmée/i)).toBeInTheDocument()
  })

  it('liste_attente -> message d’attente', async () => {
    apiClient.get.mockResolvedValueOnce({ data: pub('liste_attente') })
    renderScreen()
    expect(await screen.findByText(/vous êtes en liste d'attente/i)).toBeInTheDocument()
  })

  it('non_retenu SANS motif -> message générique fixe', async () => {
    apiClient.get.mockResolvedValueOnce({ data: pub('non_retenu', null) })
    renderScreen()
    expect(await screen.findByText(/votre candidature n'a pas été retenue/i)).toBeInTheDocument()
    expect(screen.getByText(MESSAGE_NON_RETENU_GENERIQUE)).toBeInTheDocument()
    expect(screen.queryByText(/motif :/i)).toBeNull()
  })

  it('non_retenu AVEC motif -> motif affiché, PAS le générique', async () => {
    apiClient.get.mockResolvedValueOnce({ data: pub('non_retenu', 'Places limitées, recandidatez à la prochaine cohorte.') })
    renderScreen()
    expect(await screen.findByText(/motif :/i)).toBeInTheDocument()
    expect(screen.getByText(/places limitées, recandidatez/i)).toBeInTheDocument()
    expect(screen.queryByText(MESSAGE_NON_RETENU_GENERIQUE)).toBeNull()
  })

  it('indisponible -> clôture, cause neutre', async () => {
    apiClient.get.mockResolvedValueOnce({ data: pub('indisponible') })
    renderScreen()
    expect(await screen.findByText(/votre candidature a été clôturée/i)).toBeInTheDocument()
    expect(screen.getByText(/n'a pas pu être maintenue/i)).toBeInTheDocument()
  })

  it('decision inattendue -> repli sur le message générique de non-retenue', async () => {
    apiClient.get.mockResolvedValueOnce({ data: pub('valeur_bizarre') })
    renderScreen()
    expect(await screen.findByText(/votre candidature n'a pas été retenue/i)).toBeInTheDocument()
    expect(screen.getByText(MESSAGE_NON_RETENU_GENERIQUE)).toBeInTheDocument()
  })
})

describe('MaCandidature — NON-FUITE : aucun état interne, quel que soit l’état', () => {
  const combos = [
    ['brouillon', null, null],
    ['en_cours_de_traitement', null, null],
    ['decision_publiee', 'retenu', null],
    ['decision_publiee', 'liste_attente', null],
    ['decision_publiee', 'non_retenu', null],
    ['decision_publiee', 'non_retenu', 'Un motif communicable saisi par l’admin.'],
    ['decision_publiee', 'indisponible', null],
    ['decision_publiee', null, null],
  ]

  it.each(combos)('%s / %s / motif=%s -> DOM sans terme interne', async (statut, decision, motif) => {
    apiClient.get.mockResolvedValueOnce({
      data: payload({
        statut_public: statut,
        decision,
        motif_communicable: motif,
        date_soumission: statut === 'brouillon' ? null : '2026-06-01T09:00:00+00:00',
        // bruit interne qui NE DOIT PAS influencer le rendu ni fuiter :
        statut_interne: 'non_eligible',
        statut_eligibilite_interne: 'non_eligible',
        rang: 7,
        motif_interne: 'non éligible — critère âge',
        score_total: 88.5,
      }),
    })
    const { container } = renderScreen()
    // attendre la fin du chargement (au moins un h3 apparait dans tous les cas)
    await screen.findAllByRole('heading', { level: 3 })
    expect(container.textContent).not.toMatch(TERMES_INTERNES)
  })
})

describe('MaCandidature — INDISCERNABILITÉ des deux non_retenu', () => {
  it('non_retenu « issu d’un non-éligible » et non_retenu ordinaire -> DOM identique', async () => {
    // 1) non_retenu ordinaire, payload minimal
    apiClient.get.mockResolvedValueOnce({
      data: payload({ statut_public: 'decision_publiee', decision: 'non_retenu', motif_communicable: null }),
    })
    const a = renderScreen()
    await screen.findByText(/n'a pas été retenue/i)
    const domOrdinaire = a.container.innerHTML
    a.unmount()

    // 2) non_retenu « qui vient d’un non-éligible » — le serveur strippe déjà
    //    motif_interne/statut_interne ; on simule un payload qui en contiendrait
    //    par erreur : le rendu doit être STRICTEMENT le même.
    apiClient.get.mockResolvedValueOnce({
      data: payload({
        statut_public: 'decision_publiee',
        decision: 'non_retenu',
        motif_communicable: null,
        statut_interne: 'non_eligible',
        motif_interne: 'non éligible',
        rang: null,
        critere_eliminatoire_declenche: [{ code: 'age' }],
      }),
    })
    const b = renderScreen()
    await screen.findByText(/n'a pas été retenue/i)
    const domNonEligible = b.container.innerHTML

    expect(domNonEligible).toBe(domOrdinaire)
  })
})
