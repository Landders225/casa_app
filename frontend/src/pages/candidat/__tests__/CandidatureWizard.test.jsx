import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { useAuth } from '../../../auth/useAuth.js'
import { CandidatureWizard } from '../CandidatureWizard.jsx'

vi.mock('../../../lib/apiClient.js', () => ({
  apiClient: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), del: vi.fn(), postForm: vi.fn() },
}))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

const FILIERES = [
  { id: 'f-cui', code: 'cuisine', nom: 'Agent de cuisine', description: 'Cuisine.', actif: true },
  { id: 'f-bua', code: 'buanderie', nom: 'Agent de buanderie', description: 'Linge.', actif: true },
  { id: 'f-acc', code: 'accueil-reception', nom: 'Accueil-réception', description: 'Accueil.', actif: true },
  { id: 'f-res', code: 'restaurant-bar', nom: 'Service restaurant-bar', description: 'Salle.', actif: true },
  { id: 'f-ent', code: 'entretien-hotelier', nom: 'Entretien hôtelier', description: 'Ménage.', actif: true },
]

const PROFIL = {
  email: 'aya@example.ci', prenom: 'Aya', nom: 'Traoré', sexe: 'F',
  date_naissance: '2001-05-14', cni: 'CI001', telephone: '0700', ville_residence: 'Abidjan',
}

function candidatureResource(over = {}) {
  return {
    id: 'c-1',
    numero_dossier: 'CASA-2026-000009',
    statut_public: 'brouillon',
    cqp_confirme: false,
    filiere: { id: 'f-cui', code: 'cuisine', nom: 'Agent de cuisine' },
    reponses: {},
    experiences: [],
    classement: FILIERES.map((f, i) => ({ rang: i + 1, filiere: { id: f.id, code: f.code, nom: f.nom } })),
    pieces_dossier: [],
    ...over,
  }
}

/** Candidature « complète » côté client (tous les gates `stepErrors` passent). */
function candidatureComplete(over = {}) {
  return candidatureResource({
    cqp_confirme: true,
    reponses: {
      sc01_scolarise_actuellement: 'non', sc02_derniere_classe: '3e',
      sc03_document_justifiant_niveau: 'oui', sc05_beneficiaire_formation_actuelle: 'oui',
      se02_orphelin: 'non', se03_situation_emploi: 'stage', se06_soutien_menage: 'non',
      langue_ecrit: 2, langue_parle: 2, langue_comprehension: 2,
      info_word: 1, info_excel: 1, info_internet: 1,
      mo04_lettre_motivation: 'Je souhaite rejoindre cette formation pour bâtir une carrière solide.',
      di01_disponible_lun_ven: 'oui', di02_contraintes: 'aucune', di03_engagement_complet: 'oui',
      acces_plateau: 'oui', acces_deux_plateaux_vallons: 'oui',
    },
    pieces_dossier: ['cni', 'residence', 'diplome', 'cv', 'lettre', 'photo']
      .map((t) => ({ id: `p-${t}`, type_document_code: t })),
    ...over,
  })
}

function renderWizard() {
  return render(
    <MemoryRouter initialEntries={['/candidat/candidature']}>
      <Routes>
        <Route path="/candidat/candidature" element={<CandidatureWizard />} />
        <Route path="/candidat" element={<div>tableau de bord</div>} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('CandidatureWizard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useAuth.mockReturnValue({
      user: { email: PROFIL.email, profil: PROFIL },
      role: 'candidat',
      status: 'authenticated',
      logout: vi.fn().mockResolvedValue(undefined),
    })
    apiClient.get.mockImplementation((path) => {
      if (path === '/filieres') return Promise.resolve({ data: FILIERES })
      if (path === '/candidature') return Promise.reject(new ApiError('http', { status: 404 }))
      return Promise.reject(new ApiError('http', { status: 404 }))
    })
  })
  afterEach(() => vi.restoreAllMocks())

  it('démarre à l’étape 1 quand il n’y a pas encore de candidature (404)', async () => {
    renderWizard()
    expect(await screen.findByRole('heading', { name: /vos informations personnelles/i })).toBeInTheDocument()
    expect(screen.getByText('Étape 1 / 10')).toBeInTheDocument()
  })

  it('« Suivant » de l’étape 1 ATTEND le PATCH profil avant d’avancer, et ne l’ignore pas s’il échoue', async () => {
    let resolveProfil
    apiClient.patch.mockImplementation((path) => {
      if (path === '/candidat/profil') return new Promise((res) => { resolveProfil = () => res({ data: PROFIL }) })
      return Promise.resolve({ data: {} })
    })
    renderWizard()
    await screen.findByRole('heading', { name: /vos informations personnelles/i })
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: /suivant/i }))
    // Tant que le PATCH n'est pas résolu, on reste à l'étape 1.
    expect(screen.getByText('Étape 1 / 10')).toBeInTheDocument()
    expect(apiClient.patch).toHaveBeenCalledWith('/candidat/profil', expect.objectContaining({ prenom: 'Aya' }))

    resolveProfil()
    await waitFor(() => expect(screen.getByText('Étape 2 / 10')).toBeInTheDocument())
  })

  it('un échec de sauvegarde du brouillon empêche d’avancer et affiche une alerte', async () => {
    apiClient.patch.mockRejectedValue(new ApiError('network', { message: 'offline' }))
    renderWizard()
    await screen.findByRole('heading', { name: /vos informations personnelles/i })
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: /suivant/i }))

    expect(await screen.findByRole('alert')).toHaveTextContent(/la sauvegarde a échoué/i)
    expect(screen.getByText('Étape 1 / 10')).toBeInTheDocument()
  })

  it('étape 2 : crée la candidature puis la confirme', async () => {
    apiClient.patch.mockResolvedValue({ data: PROFIL })
    apiClient.post.mockImplementation((path) => {
      if (path === '/candidatures') return Promise.resolve({ data: candidatureResource() })
      if (path === '/candidatures/c-1/confirmer-filiere') return Promise.resolve({ data: candidatureResource({ cqp_confirme: true }) })
      return Promise.resolve({ data: {} })
    })
    renderWizard()
    await screen.findByRole('heading', { name: /vos informations personnelles/i })
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: /suivant/i }))
    await screen.findByText('Étape 2 / 10')

    // Choisir une filière + cocher la confirmation
    await user.click(screen.getByRole('button', { name: /agent de cuisine/i }))
    await user.click(screen.getByLabelText(/je confirme/i))
    await user.click(screen.getByRole('button', { name: /suivant/i }))

    expect(apiClient.post).toHaveBeenCalledWith('/candidatures', { filiere_id: 'f-cui' })
    expect(apiClient.post).toHaveBeenCalledWith('/candidatures/c-1/confirmer-filiere')
    await waitFor(() => expect(screen.getByText('Étape 3 / 10')).toBeInTheDocument())
  })

  it('étape 2 : sans confirmation cochée, « Suivant » est bloqué', async () => {
    apiClient.patch.mockResolvedValue({ data: PROFIL })
    apiClient.post.mockResolvedValue({ data: candidatureResource() })
    renderWizard()
    await screen.findByRole('heading', { name: /vos informations personnelles/i })
    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /suivant/i }))
    await screen.findByText('Étape 2 / 10')

    await user.click(screen.getByRole('button', { name: /agent de cuisine/i }))
    await user.click(screen.getByRole('button', { name: /suivant/i }))

    expect(screen.getByText('Étape 2 / 10')).toBeInTheDocument()
    expect(screen.getByText(/confirmez votre filière/i)).toBeInTheDocument()
  })

  it('reprise : une candidature brouillon existante est ré-hydratée', async () => {
    apiClient.get.mockImplementation((path) => {
      if (path === '/filieres') return Promise.resolve({ data: FILIERES })
      if (path === '/candidature') {
        return Promise.resolve({
          data: candidatureResource({
            cqp_confirme: true,
            reponses: { sc01_scolarise_actuellement: 'non', mo04_lettre_motivation: 'x'.repeat(40) },
          }),
        })
      }
      return Promise.reject(new ApiError('http', { status: 404 }))
    })
    renderWizard()
    await screen.findByRole('heading', { name: /vos informations personnelles/i })
    const user = userEvent.setup()

    // Aller directement à l'étape scolaire via Suivant×2 (profil + filière déjà confirmée)
    apiClient.patch.mockResolvedValue({ data: PROFIL })
    apiClient.post.mockResolvedValue({ data: candidatureResource({ cqp_confirme: true }) })
    await user.click(screen.getByRole('button', { name: /suivant/i }))
    await screen.findByText('Étape 2 / 10')
    await user.click(screen.getByRole('button', { name: /suivant/i }))
    await screen.findByText('Étape 3 / 10')

    // La réponse SC.01 pré-remplie est bien sélectionnée.
    const sc01 = screen.getByRole('radiogroup', { name: /actuellement scolarisé/i })
    expect(within(sc01).getByRole('radio', { name: 'Non' })).toBeChecked()
  })

  it('étape expérience : renseigner domaine + durée matérialise la ligne côté serveur', async () => {
    apiClient.get.mockImplementation((path) => {
      if (path === '/filieres') return Promise.resolve({ data: FILIERES })
      if (path === '/candidature') return Promise.resolve({ data: candidatureComplete() })
      return Promise.reject(new ApiError('http', { status: 404 }))
    })
    apiClient.patch.mockResolvedValue({ data: PROFIL })
    apiClient.post.mockImplementation((path) => {
      if (path === '/candidatures/c-1/experiences') {
        return Promise.resolve({ data: { id: 'exp-9', domaine: 'hotellerie', duree_categorie: '6_12', justificatif: null } })
      }
      return Promise.resolve({ data: candidatureComplete() })
    })

    renderWizard()
    await screen.findByRole('heading', { name: /vos informations personnelles/i })
    const user = userEvent.setup()
    for (let i = 0; i < 4; i++) {
      // eslint-disable-next-line no-await-in-loop
      await user.click(screen.getByRole('button', { name: /^suivant/i }))
      // eslint-disable-next-line no-await-in-loop
      await waitFor(() => expect(screen.getByText(`Étape ${i + 2} / 10`)).toBeInTheDocument())
    }

    await user.click(screen.getByRole('button', { name: /ajouter une expérience/i }))
    await user.selectOptions(screen.getByLabelText('Domaine'), 'hotellerie')
    await user.selectOptions(screen.getByLabelText('Durée'), '6_12')

    await waitFor(() =>
      expect(apiClient.post).toHaveBeenCalledWith('/candidatures/c-1/experiences', {
        domaine: 'hotellerie', duree_categorie: '6_12',
      }),
    )
    // La ligne est « prête » : le dépôt de justificatif devient possible.
    expect(await screen.findByRole('button', { name: /déposer/i })).toBeInTheDocument()
  })

  it('soumission 422 de complétude : liste les manques avec des boutons « Corriger » qui sautent à la bonne étape', async () => {
    apiClient.get.mockImplementation((path) => {
      if (path === '/filieres') return Promise.resolve({ data: FILIERES })
      if (path === '/candidature') return Promise.resolve({ data: candidatureComplete() })
      return Promise.reject(new ApiError('http', { status: 404 }))
    })
    apiClient.patch.mockResolvedValue({ data: PROFIL })
    apiClient.put.mockResolvedValue({ data: candidatureComplete() })
    apiClient.post.mockImplementation((path) => {
      if (path.endsWith('/soumettre')) {
        return Promise.reject(new ApiError('validation', {
          status: 422,
          message: 'Dossier incomplet.',
          errors: {
            'reponses.sc01_scolarise_actuellement': ['Champ obligatoire.'],
            pieces_dossier: ['Pièces manquantes : cni, cv.'],
          },
        }))
      }
      return Promise.resolve({ data: candidatureComplete() })
    })

    renderWizard()
    await screen.findByRole('heading', { name: /vos informations personnelles/i })
    const user = userEvent.setup()

    // Naviguer jusqu'au récap : la candidature hydratée est complète côté client.
    for (let i = 0; i < 9; i++) {
      // eslint-disable-next-line no-await-in-loop
      await user.click(screen.getByRole('button', { name: /^suivant/i }))
      // eslint-disable-next-line no-await-in-loop
      await waitFor(() => expect(screen.getByText(`Étape ${i + 2} / 10`)).toBeInTheDocument())
    }

    await user.click(screen.getByLabelText(/je certifie/i))
    await user.click(screen.getByRole('button', { name: /soumettre ma candidature/i }))

    expect(await screen.findByText('Dossier incomplet')).toBeInTheDocument()
    const corriger = screen.getAllByRole('button', { name: 'Corriger' })
    expect(corriger).toHaveLength(2)

    // « Corriger » sur les pièces → saute à l'étape 9 (Pièces justificatives).
    await user.click(corriger[1])
    await waitFor(() => expect(screen.getByText('Étape 9 / 10')).toBeInTheDocument())
  })
})
