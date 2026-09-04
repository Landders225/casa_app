import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { FicheCandidat } from '../FicheCandidat.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn(), put: vi.fn() } }))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

function dossier(over = {}) {
  return {
    id: 'c-1',
    numero_dossier: 'CASA-2026-000001',
    statut_interne: 'en_instruction',
    statut_eligibilite_interne: 'eligible',
    date_soumission: '2026-06-01T00:00:00Z',
    date_evaluation: null,
    cqp_confirme: true,
    dossier_verrouille: false,
    commentaire_evaluateur: null,
    filiere: { id: 'f1', code: 'cuisine', nom: 'Agent de cuisine' },
    campagne: { id: 'k1', nom: 'Cohorte 2026' },
    evaluateur: { id: 'm1', prenom: 'Solange', nom: "N'Dri", poste: "Chargée d'évaluation" },
    candidat: {
      id: 'cand-1', prenom: 'Awa', nom: 'Konan', sexe: 'F', date_naissance: '2003-04-12',
      cni: 'CI102030405', telephone: '0701020304', ville_residence: 'Abidjan - Cocody', residence_ci: true,
    },
    reponses: {
      sc01_scolarise_actuellement: 'non', sc02_derniere_classe: 'terminale', sc03_document_justifiant_niveau: 'oui',
      sc05_beneficiaire_formation_actuelle: 'non', sc06_deja_beneficie_formation: null,
      se02_orphelin: 'non', se03_situation_emploi: 'sans_emploi',
      langue_ecrit: 2, langue_parle: 3, langue_comprehension: 2, info_word: 1, info_excel: 0, info_internet: 2,
      mo04_lettre_motivation: 'Je souhaite integrer ce programme.', mo04_note_etoiles: null,
      di01_disponible_lun_ven: 'oui', di02_contraintes: 'aucune', di03_engagement_complet: 'oui',
      acces_plateau: 'oui', acces_deux_plateaux_vallons: 'non',
    },
    experiences: [],
    pieces_dossier: [],
    verification: { nationalite_confirmee: null, diplome_verifie: null, verifie_le: null, verifie_par: null },
    criteres_eliminatoires: [],
    evaluation: null,
    entretien: null,
    ...over,
  }
}

function renderFiche(id = 'c-1') {
  return render(
    <MemoryRouter initialEntries={[`/evaluateur/candidatures/${id}`]}>
      <Routes>
        <Route path="/evaluateur/candidatures/:id" element={<FicheCandidat />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuth.mockReturnValue({
    user: { email: 'eval@casa-demo.ci', profil: { prenom: 'Solange', nom: "N'Dri" } },
    role: 'evaluateur',
    logout: vi.fn(),
  })
})
afterEach(() => vi.restoreAllMocks())

describe('FicheCandidat — la zone 🔴 est affichée LÉGITIMEMENT (inversion de posture)', () => {
  it('statut interne, réponses déclarées : tout est visible', async () => {
    apiClient.get.mockResolvedValueOnce({ data: dossier() })
    renderFiche()

    expect(await screen.findByRole('heading', { name: 'Awa Konan' })).toBeInTheDocument()
    // statut_interne — INTERNE, illisible côté candidat, légitime ici :
    expect(screen.getByText('En cours d’instruction')).toBeInTheDocument()

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Scolarité' }))
    // une réponse déclarée par le candidat, lue via la Resource ÉVALUATEUR :
    const sc01Row = screen.getByText('Actuellement scolarisé(e) ?').closest('.qa-row')
    expect(within(sc01Row).getByText('Non')).toBeInTheDocument()
    expect(screen.getByText('Terminale')).toBeInTheDocument() // sc02
  })

  it('éligible + aucun critère -> bannière succès, jamais de calcul client', async () => {
    apiClient.get.mockResolvedValueOnce({ data: dossier() })
    renderFiche()
    expect(await screen.findByText('Éligible')).toBeInTheDocument()
    expect(screen.getByText('Aucun critère éliminatoire détecté.')).toBeInTheDocument()
  })

  it('non éligible -> le `detail` du critère est affiché TEL QUEL (texte serveur)', async () => {
    apiClient.get.mockResolvedValueOnce({
      data: dossier({
        statut_eligibilite_interne: 'non_eligible',
        criteres_eliminatoires: [
          { code_critere: 'DI.01', detail: 'Non disponible du lundi au vendredi', origine: 'soumission_candidat', declenche_le: '2026-06-01T00:00:00Z' },
        ],
      }),
    })
    renderFiche()
    expect(await screen.findByText('Non éligible')).toBeInTheDocument()
    expect(screen.getByText(/Non disponible du lundi au vendredi/)).toBeInTheDocument()
    expect(screen.getByText('À la soumission')).toBeInTheDocument()
  })
})

describe('FicheCandidat — dossier introuvable / non affecté (404)', () => {
  it('affiche un état "candidat introuvable", pas une erreur brute', async () => {
    apiClient.get.mockRejectedValueOnce(new ApiError('http', { status: 404 }))
    renderFiche()
    expect(await screen.findByText(/candidat introuvable/i)).toBeInTheDocument()
  })
})

describe('FicheCandidat — vérification : reflet EN DIRECT de la Resource fraîche', () => {
  it('diplome=cepe -> le panneau Éligibilité change immédiatement, sans recalcul ni refetch', async () => {
    apiClient.get.mockResolvedValueOnce({ data: dossier() }) // eligible, 0 critère
    apiClient.put.mockResolvedValueOnce({
      data: dossier({
        statut_eligibilite_interne: 'non_eligible',
        verification: { nationalite_confirmee: null, diplome_verifie: 'cepe', verifie_le: '2026-06-10T09:00:00Z', verifie_par: { id: 'm1', prenom: 'Solange', nom: "N'Dri" } },
        criteres_eliminatoires: [
          { code_critere: 'SC.04', detail: 'Plus haut diplôme confirmé = CEPE', origine: 'verification_evaluateur', declenche_le: '2026-06-10T09:00:00Z' },
        ],
      }),
    })

    renderFiche()
    await screen.findByRole('heading', { name: 'Awa Konan' })
    expect(screen.getByText('Éligible')).toBeInTheDocument() // état AVANT

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Vérification' }))
    await user.click(screen.getByRole('radio', { name: 'CEPE' }))
    await user.click(screen.getByRole('button', { name: /enregistrer la vérification/i }))

    // Un seul appel réseau pour la vérification : PAS de refetch séparé du dossier.
    await waitFor(() => expect(apiClient.put).toHaveBeenCalledTimes(1))
    expect(apiClient.put).toHaveBeenCalledWith(
      '/evaluateur/candidatures/c-1/verification',
      { nationalite_confirmee: null, diplome_verifie: 'cepe' },
    )
    expect(apiClient.get).toHaveBeenCalledTimes(1) // aucun refetch déclenché par la sauvegarde

    // Le panneau Éligibilité reflète EXACTEMENT la réponse du PUT — état APRÈS.
    expect(await screen.findByText('Non éligible')).toBeInTheDocument()
    expect(screen.getByText(/Plus haut diplôme confirmé = CEPE/)).toBeInTheDocument()
    expect(screen.getByText('Vérification évaluateur')).toBeInTheDocument()
    expect(screen.getByText('Vérification enregistrée.')).toBeInTheDocument()
  })

  it('dossier non "en_instruction" -> formulaire non modifiable (miroir du 409 backend)', async () => {
    apiClient.get.mockResolvedValueOnce({ data: dossier({ statut_interne: 'evalue' }) })
    renderFiche()
    await screen.findByRole('heading', { name: 'Awa Konan' })
    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Vérification' }))
    expect(screen.getByText(/n'est modifiable que lorsque le dossier est/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /enregistrer la vérification/i })).toBeDisabled()
  })
})
