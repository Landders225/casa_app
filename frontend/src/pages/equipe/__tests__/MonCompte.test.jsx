import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { apiClient } from '../../../lib/apiClient.js'
import { MonCompte } from '../MonCompte.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn(), put: vi.fn() } }))
vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

function renderScreen() {
  return render(<MemoryRouter><MonCompte /></MemoryRouter>)
}

/** `AppShell` (Lot 12c) appelle aussi `GET /candidat/notifications/compteur` — inoffensif ici (rôle non-candidat, jamais monté). */
beforeEach(() => {
  vi.clearAllMocks()
  apiClient.get.mockResolvedValue({ data: null })
})
afterEach(() => vi.restoreAllMocks())

describe('MonCompte — self-service équipe (Lot 15a)', () => {
  it('affiche l’identité en LECTURE SEULE (évaluateur)', () => {
    useAuth.mockReturnValue({
      user: { email: 'solange@cci.ci', profil: { prenom: 'Solange', nom: "N'Dri", poste: "Chargée d'évaluation" } },
      role: 'evaluateur',
      logout: vi.fn(),
    })
    renderScreen()

    expect(screen.getByLabelText('Prénom')).toHaveValue('Solange')
    expect(screen.getByLabelText('Prénom')).toBeDisabled()
    expect(screen.getByLabelText('Nom')).toHaveValue("N'Dri")
    expect(screen.getByLabelText('Poste')).toHaveValue("Chargée d'évaluation")
    expect(screen.getByLabelText('E-mail')).toHaveValue('solange@cci.ci')
    expect(screen.getByLabelText('Rôle')).toHaveValue('Évaluateur')
    expect(screen.getByLabelText('Rôle')).toBeDisabled()
  })

  it('affiche l’identité en lecture seule (administrateur) — même écran partagé', () => {
    useAuth.mockReturnValue({
      user: { email: 'prisca@cci.ci', profil: { prenom: 'Prisca', nom: 'Yéo', poste: 'Coordination projet CASA' } },
      role: 'administrateur',
      logout: vi.fn(),
    })
    renderScreen()

    expect(screen.getByLabelText('Prénom')).toHaveValue('Prisca')
    expect(screen.getByLabelText('Rôle')).toHaveValue('Administrateur')
  })

  it('renvoie vers un administrateur pour corriger l’identité — aucun champ éditable ici', () => {
    useAuth.mockReturnValue({
      user: { email: 'solange@cci.ci', profil: { prenom: 'Solange', nom: "N'Dri", poste: 'Jury' } },
      role: 'evaluateur',
      logout: vi.fn(),
    })
    renderScreen()

    expect(screen.getByText(/contactez un administrateur/i)).toBeInTheDocument()
    expect(document.querySelectorAll('input:not([disabled])').length).toBeGreaterThan(0) // les champs du mot de passe, eux, sont actifs
  })

  it('change de mot de passe via PUT /equipe/mot-de-passe (pas /candidat/...)', async () => {
    useAuth.mockReturnValue({
      user: { email: 'solange@cci.ci', profil: { prenom: 'Solange', nom: "N'Dri", poste: 'Jury' } },
      role: 'evaluateur',
      logout: vi.fn(),
    })
    apiClient.put.mockResolvedValueOnce({ message: 'ok' })
    renderScreen()

    const user = userEvent.setup()
    await user.type(screen.getByLabelText('Mot de passe actuel'), 'MotDePasse2026')
    await user.type(screen.getByLabelText('Nouveau mot de passe'), 'NouveauMdp2026')
    await user.type(screen.getByLabelText('Confirmer le nouveau mot de passe'), 'NouveauMdp2026')
    await user.click(screen.getByRole('button', { name: /changer mon mot de passe/i }))

    expect(apiClient.put).toHaveBeenCalledWith('/equipe/mot-de-passe', expect.objectContaining({
      current_password: 'MotDePasse2026',
    }))
  })
})
