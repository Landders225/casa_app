import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { NouveauMotDePasse } from '../NouveauMotDePasse.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { post: vi.fn() } }))

function renderPage(query = '?token=abc123&email=x%40y.ci') {
  return render(
    <MemoryRouter initialEntries={[`/mot-de-passe/nouveau${query}`]}>
      <Routes>
        <Route path="/mot-de-passe/nouveau" element={<NouveauMotDePasse />} />
        <Route path="/connexion" element={<div>écran de connexion</div>} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => vi.clearAllMocks())
afterEach(() => vi.restoreAllMocks())

describe('NouveauMotDePasse (Lot 13, ADR-32)', () => {
  it('lit token + email depuis la query string et les envoie', async () => {
    apiClient.post.mockResolvedValueOnce({ message: 'ok' })
    renderPage()

    const user = userEvent.setup()
    await user.type(screen.getByLabelText('Nouveau mot de passe'), 'NouveauMdp2026')
    await user.type(screen.getByLabelText('Confirmer le nouveau mot de passe'), 'NouveauMdp2026')
    await user.click(screen.getByRole('button', { name: /réinitialiser mon mot de passe/i }))

    expect(apiClient.post).toHaveBeenCalledWith('/mot-de-passe/reinitialiser', {
      token: 'abc123', email: 'x@y.ci', password: 'NouveauMdp2026', password_confirmation: 'NouveauMdp2026',
    })
  })

  it('succès -> redirige vers /connexion', async () => {
    apiClient.post.mockResolvedValueOnce({ message: 'ok' })
    renderPage()

    const user = userEvent.setup()
    await user.type(screen.getByLabelText('Nouveau mot de passe'), 'NouveauMdp2026')
    await user.type(screen.getByLabelText('Confirmer le nouveau mot de passe'), 'NouveauMdp2026')
    await user.click(screen.getByRole('button', { name: /réinitialiser mon mot de passe/i }))

    expect(await screen.findByText('écran de connexion')).toBeInTheDocument()
  })

  it('lien invalide ou expiré (422) -> message affiché VERBATIM', async () => {
    apiClient.post.mockRejectedValueOnce(new ApiError('validation', {
      status: 422, message: 'Ce lien de réinitialisation est invalide ou a expiré.', errors: {},
    }))
    renderPage()

    const user = userEvent.setup()
    await user.type(screen.getByLabelText('Nouveau mot de passe'), 'NouveauMdp2026')
    await user.type(screen.getByLabelText('Confirmer le nouveau mot de passe'), 'NouveauMdp2026')
    await user.click(screen.getByRole('button', { name: /réinitialiser mon mot de passe/i }))

    expect(await screen.findByText('Ce lien de réinitialisation est invalide ou a expiré.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /redemander un lien/i })).toHaveAttribute('href', '/mot-de-passe/oublie')
  })

  it('mot de passe trop faible -> 422 de champ', async () => {
    apiClient.post.mockRejectedValueOnce(new ApiError('validation', {
      status: 422, errors: { password: ['Le mot de passe doit contenir au moins 10 caractères.'] },
    }))
    renderPage()

    const user = userEvent.setup()
    await user.type(screen.getByLabelText('Nouveau mot de passe'), 'faible')
    await user.type(screen.getByLabelText('Confirmer le nouveau mot de passe'), 'faible')
    await user.click(screen.getByRole('button', { name: /réinitialiser mon mot de passe/i }))

    expect(await screen.findByText('Le mot de passe doit contenir au moins 10 caractères.')).toBeInTheDocument()
  })

  it('lien incomplet (token ou email manquant) -> formulaire non affiché, aucun appel réseau', () => {
    renderPage('?token=abc123') // pas d'email
    expect(screen.getByText(/lien de réinitialisation est incomplet/i)).toBeInTheDocument()
    expect(screen.queryByLabelText('Nouveau mot de passe')).not.toBeInTheDocument()
    expect(apiClient.post).not.toHaveBeenCalled()
  })
})
