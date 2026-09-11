import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { apiClient } from '../../../lib/apiClient.js'
import { ApiError } from '../../../lib/ApiError.js'
import { MotDePasseOublie } from '../MotDePasseOublie.jsx'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { post: vi.fn() } }))

function renderPage() {
  return render(<MemoryRouter><MotDePasseOublie /></MemoryRouter>)
}

beforeEach(() => vi.clearAllMocks())
afterEach(() => vi.restoreAllMocks())

/**
 * Lot 13 — l'écran affiche EXACTEMENT le même message de succès quelle que soit
 * la réponse du serveur (qui, côté backend, ne varie déjà pas — ADR-32). Cet
 * écran n'a rien à distinguer : un seul chemin de succès.
 */
describe('MotDePasseOublie — anti-énumération (Lot 13, ADR-32)', () => {
  it('affiche le formulaire', () => {
    renderPage()
    expect(screen.getByRole('heading', { name: /réinitialiser mon mot de passe/i })).toBeInTheDocument()
    expect(screen.getByLabelText('Adresse e-mail')).toBeInTheDocument()
  })

  it('soumission -> message générique affiché, quel que soit le retour serveur', async () => {
    apiClient.post.mockResolvedValueOnce({ message: 'Si un compte existe pour cette adresse, un lien de réinitialisation vient d\'être envoyé.' })
    renderPage()

    const user = userEvent.setup()
    await user.type(screen.getByLabelText('Adresse e-mail'), 'x@y.ci')
    await user.click(screen.getByRole('button', { name: /envoyer le lien/i }))

    expect(apiClient.post).toHaveBeenCalledWith('/mot-de-passe/oubli', { email: 'x@y.ci' })
    expect(await screen.findByText(/vérifiez votre boîte de réception/i)).toBeInTheDocument()
    expect(screen.getByText(/si un compte existe pour cette adresse/i)).toBeInTheDocument()
  })

  it('le formulaire disparaît après succès (pas de double-soumission)', async () => {
    apiClient.post.mockResolvedValueOnce({ message: 'ok' })
    renderPage()

    const user = userEvent.setup()
    await user.type(screen.getByLabelText('Adresse e-mail'), 'x@y.ci')
    await user.click(screen.getByRole('button', { name: /envoyer le lien/i }))

    await screen.findByText(/vérifiez votre boîte de réception/i)
    expect(screen.queryByLabelText('Adresse e-mail')).not.toBeInTheDocument()
  })

  it('e-mail mal formé -> 422 de champ affiché, PAS le message de succès', async () => {
    apiClient.post.mockRejectedValueOnce(new ApiError('validation', {
      status: 422, errors: { email: ["L'adresse e-mail n'est pas valide."] },
    }))
    renderPage()

    const user = userEvent.setup()
    await user.type(screen.getByLabelText('Adresse e-mail'), 'pas-un-email')
    await user.click(screen.getByRole('button', { name: /envoyer le lien/i }))

    expect(await screen.findByText("L'adresse e-mail n'est pas valide.")).toBeInTheDocument()
    expect(screen.queryByText(/vérifiez votre boîte de réception/i)).not.toBeInTheDocument()
  })

  it('429 -> message « trop de tentatives »', async () => {
    apiClient.post.mockRejectedValueOnce(new ApiError('rate_limited', { status: 429 }))
    renderPage()

    const user = userEvent.setup()
    await user.type(screen.getByLabelText('Adresse e-mail'), 'x@y.ci')
    await user.click(screen.getByRole('button', { name: /envoyer le lien/i }))

    expect(await screen.findByText(/trop de tentatives/i)).toBeInTheDocument()
  })

  it('lien de retour vers la connexion présent', () => {
    renderPage()
    expect(screen.getByRole('link', { name: /retour à la connexion/i })).toHaveAttribute('href', '/connexion')
  })
})
