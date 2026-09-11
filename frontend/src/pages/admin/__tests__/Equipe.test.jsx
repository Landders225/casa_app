import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { Equipe } from '../Equipe.jsx'

vi.mock('../../../lib/apiClient.js', () => ({
  apiClient: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
}))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

const ADMIN_ID = 'admin-1'

function membre(over = {}) {
  return {
    id: 'm-1', email: 'awa@cci.ci', role: 'evaluateur', actif: true,
    prenom: 'Awa', nom: 'Traoré', poste: 'Jury cuisine',
    derniere_connexion_le: null, dossiers_affectes: 3, dossiers_evalues: 1, ...over,
  }
}

function renderScreen() {
  return render(<MemoryRouter><Equipe /></MemoryRouter>)
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuth.mockReturnValue({
    user: { id: ADMIN_ID, profil: { prenom: 'Prisca', nom: 'Yéo' } },
    role: 'administrateur',
    logout: vi.fn(),
  })
})
afterEach(() => vi.restoreAllMocks())

describe('Équipe — gestion des comptes (Lot 11b)', () => {
  it('liste les membres avec leur charge, jamais de hash', async () => {
    apiClient.get.mockResolvedValueOnce({
      data: [
        membre(),
        membre({ id: ADMIN_ID, email: 'prisca@cci.ci', role: 'administrateur', prenom: 'Prisca', nom: 'Yéo', poste: 'Coordination', dossiers_affectes: 0, dossiers_evalues: 0 }),
      ],
    })
    const { container } = renderScreen()

    await screen.findByText('Awa Traoré')
    expect(screen.getByText('prisca@cci.ci')).toBeInTheDocument()
    expect(screen.getByText(/1 \/ 3 évalués/)).toBeInTheDocument()
    expect(container.innerHTML).not.toMatch(/\$2y\$|mot_de_passe_hash/)
  })

  it('le formulaire de création ne propose que Évaluateur et Administrateur', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [] })
    renderScreen()
    await screen.findByRole('button', { name: /ajouter un membre/i })

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /ajouter un membre/i }))

    const select = within(screen.getByRole('dialog')).getByLabelText('Rôle')
    const options = within(select).getAllByRole('option').map((o) => o.textContent)
    expect(options).toEqual(['Évaluateur', 'Administrateur'])
    expect(options).not.toContain('Candidat')
  })

  it('création réussie → affiche le mot de passe temporaire une seule fois', async () => {
    apiClient.get.mockResolvedValue({ data: [] })
    apiClient.post.mockResolvedValueOnce({
      data: membre({ id: 'm-9', email: 'bob@cci.ci' }),
      mot_de_passe_temporaire: 'Xk7mPq2wRt9nZ4bH',
    })
    renderScreen()
    await screen.findByRole('button', { name: /ajouter un membre/i })

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /ajouter un membre/i }))
    const dialog = screen.getByRole('dialog')
    await user.type(within(dialog).getByLabelText('Prénom'), 'Bob')
    await user.type(within(dialog).getByLabelText('Nom'), 'Kouassi')
    await user.type(within(dialog).getByLabelText(/adresse e-mail/i), 'bob@cci.ci')
    await user.type(within(dialog).getByLabelText('Poste'), 'Jury pâtisserie')
    await user.click(within(dialog).getByRole('button', { name: /créer le compte/i }))

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith('/admin/membres', {
      prenom: 'Bob', nom: 'Kouassi', email: 'bob@cci.ci', poste: 'Jury pâtisserie', role: 'evaluateur',
    }))
    expect(await screen.findByText('Xk7mPq2wRt9nZ4bH')).toBeInTheDocument()
    expect(screen.getByText(/plus jamais affiché/i)).toBeInTheDocument()
  })

  it('422 sur un rôle refusé → message verbatim, modal ouvert', async () => {
    apiClient.get.mockResolvedValue({ data: [] })
    apiClient.post.mockRejectedValueOnce(new ApiError('validation', {
      status: 422,
      message: 'Certaines informations sont invalides.',
      errors: { role: ['Le rôle doit être « évaluateur » ou « administrateur ».'] },
    }))
    renderScreen()
    await screen.findByRole('button', { name: /ajouter un membre/i })

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /ajouter un membre/i }))
    const dialog = screen.getByRole('dialog')
    await user.type(within(dialog).getByLabelText('Prénom'), 'X')
    await user.type(within(dialog).getByLabelText('Nom'), 'Y')
    await user.type(within(dialog).getByLabelText(/adresse e-mail/i), 'x@cci.ci')
    await user.type(within(dialog).getByLabelText('Poste'), 'Z')
    await user.click(within(dialog).getByRole('button', { name: /créer le compte/i }))

    expect(await screen.findByText(/Le rôle doit être/)).toBeInTheDocument()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('« Désactiver » est neutralisé sur sa propre ligne (reflet de G1)', async () => {
    apiClient.get.mockResolvedValueOnce({
      data: [membre({ id: ADMIN_ID, email: 'prisca@cci.ci', role: 'administrateur', prenom: 'Prisca', nom: 'Yéo' })],
    })
    renderScreen()
    await screen.findByText('Prisca Yéo')

    expect(screen.getByRole('button', { name: 'Désactiver' })).toBeDisabled()
  })

  it('désactiver un autre membre → confirmation → PATCH { actif:false }', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [membre()] })
    apiClient.patch.mockResolvedValueOnce({ data: membre({ actif: false }) })
    renderScreen()
    await screen.findByText('Awa Traoré')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Désactiver' }))
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Désactiver' }))

    await waitFor(() => expect(apiClient.patch).toHaveBeenCalledWith('/admin/membres/m-1', { actif: false }))
    expect(await screen.findByText('Désactivé')).toBeInTheDocument()
  })

  it('422 G2 (dernier admin) → message verbatim sous la liste', async () => {
    apiClient.get.mockResolvedValueOnce({
      data: [membre({ id: 'a-1', role: 'administrateur', prenom: 'Koffi', nom: 'B', email: 'k@cci.ci' })],
    })
    apiClient.patch.mockRejectedValueOnce(new ApiError('http', {
      status: 422, message: 'Impossible de désactiver le dernier administrateur actif.',
    }))
    renderScreen()
    await screen.findByText('Koffi B')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Désactiver' }))
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Désactiver' }))

    expect(await screen.findByText('Impossible de désactiver le dernier administrateur actif.')).toBeInTheDocument()
  })

  it('réinitialiser → confirmation → nouveau mot de passe affiché', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [membre()] })
    apiClient.post.mockResolvedValueOnce({ mot_de_passe_temporaire: 'Nn5rTy8uMk3wPq6d' })
    renderScreen()
    await screen.findByText('Awa Traoré')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /réinitialiser le mot de passe/i }))
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Réinitialiser' }))

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith('/admin/membres/m-1/mot-de-passe'))
    expect(await screen.findByText('Nn5rTy8uMk3wPq6d')).toBeInTheDocument()
  })

  // --- Édition d'identité (Lot 15a) -----------------------------------

  it('« Modifier » ouvre un modal pré-rempli avec l’identité actuelle', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [membre()] })
    renderScreen()
    await screen.findByText('Awa Traoré')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Modifier' }))

    const dialog = screen.getByRole('dialog')
    expect(within(dialog).getByLabelText('Prénom')).toHaveValue('Awa')
    expect(within(dialog).getByLabelText('Nom')).toHaveValue('Traoré')
    expect(within(dialog).getByLabelText('Poste')).toHaveValue('Jury cuisine')
    // Ni rôle ni e-mail dans ce formulaire.
    expect(within(dialog).queryByLabelText(/rôle/i)).not.toBeInTheDocument()
    expect(within(dialog).queryByLabelText(/e-mail/i)).not.toBeInTheDocument()
  })

  it('soumission → PATCH { prenom, nom, poste } SEUL (jamais actif dans le même appel)', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [membre()] })
    apiClient.patch.mockResolvedValueOnce({ data: membre({ prenom: 'Aïcha', nom: 'Koné', poste: 'Coordination' }) })
    renderScreen()
    await screen.findByText('Awa Traoré')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Modifier' }))
    const dialog = screen.getByRole('dialog')
    await user.clear(within(dialog).getByLabelText('Prénom'))
    await user.type(within(dialog).getByLabelText('Prénom'), 'Aïcha')
    await user.clear(within(dialog).getByLabelText('Nom'))
    await user.type(within(dialog).getByLabelText('Nom'), 'Koné')
    await user.clear(within(dialog).getByLabelText('Poste'))
    await user.type(within(dialog).getByLabelText('Poste'), 'Coordination')
    await user.click(within(dialog).getByRole('button', { name: /enregistrer/i }))

    await waitFor(() => expect(apiClient.patch).toHaveBeenCalledWith('/admin/membres/m-1', {
      prenom: 'Aïcha', nom: 'Koné', poste: 'Coordination',
    }))
    expect(await screen.findByText('Aïcha Koné')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('422 (champ manquant côté serveur) → erreur verbatim, modal reste ouvert', async () => {
    apiClient.get.mockResolvedValueOnce({ data: [membre()] })
    apiClient.patch.mockRejectedValueOnce(new ApiError('validation', {
      status: 422,
      errors: { nom: ['Le prénom, le nom et le poste doivent être envoyés ensemble.'] },
    }))
    renderScreen()
    await screen.findByText('Awa Traoré')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Modifier' }))
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: /enregistrer/i }))

    expect(await screen.findByText('Le prénom, le nom et le poste doivent être envoyés ensemble.')).toBeInTheDocument()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })
})
