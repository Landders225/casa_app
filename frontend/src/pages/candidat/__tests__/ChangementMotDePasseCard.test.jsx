import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { ChangementMotDePasseCard } from '../ChangementMotDePasseCard.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { put: vi.fn() } }))

beforeEach(() => vi.clearAllMocks())
afterEach(() => vi.restoreAllMocks())

describe('ChangementMotDePasseCard (Lot 13)', () => {
  it('envoie current_password + password + password_confirmation à PUT /candidat/mot-de-passe', async () => {
    apiClient.put.mockResolvedValueOnce({ message: 'ok' })
    render(<ChangementMotDePasseCard />)

    const user = userEvent.setup()
    await user.type(screen.getByLabelText('Mot de passe actuel'), 'MotDePasse2026')
    await user.type(screen.getByLabelText('Nouveau mot de passe'), 'NouveauMdp2026')
    await user.type(screen.getByLabelText('Confirmer le nouveau mot de passe'), 'NouveauMdp2026')
    await user.click(screen.getByRole('button', { name: /changer mon mot de passe/i }))

    expect(apiClient.put).toHaveBeenCalledWith('/candidat/mot-de-passe', {
      current_password: 'MotDePasse2026', password: 'NouveauMdp2026', password_confirmation: 'NouveauMdp2026',
    })
    expect(await screen.findByText(/mot de passe a été modifié/i)).toBeInTheDocument()
    // Le formulaire est vidé après succès (pas de mot de passe qui traîne dans le DOM).
    expect(screen.getByLabelText('Mot de passe actuel')).toHaveValue('')
  })

  it('mot de passe actuel incorrect -> erreur de champ VERBATIM, rien n\'est vidé côté succès', async () => {
    apiClient.put.mockRejectedValueOnce(new ApiError('validation', {
      status: 422, errors: { current_password: ['Le mot de passe actuel est incorrect.'] },
    }))
    render(<ChangementMotDePasseCard />)

    const user = userEvent.setup()
    await user.type(screen.getByLabelText('Mot de passe actuel'), 'Mauvais2026')
    await user.type(screen.getByLabelText('Nouveau mot de passe'), 'NouveauMdp2026')
    await user.type(screen.getByLabelText('Confirmer le nouveau mot de passe'), 'NouveauMdp2026')
    await user.click(screen.getByRole('button', { name: /changer mon mot de passe/i }))

    expect(await screen.findByText('Le mot de passe actuel est incorrect.')).toBeInTheDocument()
    expect(screen.queryByText(/mot de passe a été modifié/i)).not.toBeInTheDocument()
  })

  it('mentionne que les AUTRES sessions seront déconnectées', async () => {
    apiClient.put.mockResolvedValueOnce({ message: 'ok' })
    render(<ChangementMotDePasseCard />)

    const user = userEvent.setup()
    await user.type(screen.getByLabelText('Mot de passe actuel'), 'MotDePasse2026')
    await user.type(screen.getByLabelText('Nouveau mot de passe'), 'NouveauMdp2026')
    await user.type(screen.getByLabelText('Confirmer le nouveau mot de passe'), 'NouveauMdp2026')
    await user.click(screen.getByRole('button', { name: /changer mon mot de passe/i }))

    expect(await screen.findByText(/autres sessions ont été déconnectées/i)).toBeInTheDocument()
  })
})
