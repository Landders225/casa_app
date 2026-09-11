import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { apiClient } from '../../../lib/apiClient.js'
import { AppShell } from '../AppShell.jsx'

vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))
vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { get: vi.fn() } }))

describe('AppShell — prop `space` (Lot 8c-1, recouvrement admin ⊇ évaluateur)', () => {
  beforeEach(() => vi.clearAllMocks())

  it('sans `space` : la navigation suit le rôle (comportement inchangé)', () => {
    useAuth.mockReturnValue({ user: { profil: { prenom: 'Prisca', nom: 'Yéo' } }, role: 'administrateur', logout: vi.fn() })
    render(<MemoryRouter><AppShell title="t">contenu</AppShell></MemoryRouter>)
    expect(screen.getByText('Campagnes')).toBeInTheDocument() // nav admin
    expect(screen.queryByText('Mes dossiers')).not.toBeInTheDocument()
  })

  it('admin + space="evaluateur" : la sidebar est celle de l’évaluateur, le pied garde le VRAI rôle', () => {
    useAuth.mockReturnValue({ user: { profil: { prenom: 'Prisca', nom: 'Yéo' } }, role: 'administrateur', logout: vi.fn() })
    render(<MemoryRouter><AppShell title="t" space="evaluateur">contenu</AppShell></MemoryRouter>)

    // navigation = évaluateur (où on est)
    expect(screen.getByText('Mes dossiers')).toBeInTheDocument()
    expect(screen.queryByText('Campagnes')).not.toBeInTheDocument()
    // identité = vrai rôle (qui on est)
    expect(screen.getByText('Administrateur')).toBeInTheDocument()
  })

  it('« Mes dossiers » est un vrai lien cliquable (pas inerte) pour un évaluateur', () => {
    useAuth.mockReturnValue({ user: { profil: { prenom: 'Solange', nom: "N'Dri" } }, role: 'evaluateur', logout: vi.fn() })
    render(<MemoryRouter><AppShell title="t">contenu</AppShell></MemoryRouter>)
    const link = screen.getByRole('link', { name: /mes dossiers/i })
    expect(link).toHaveAttribute('href', '/evaluateur/mes-dossiers')
  })
})

describe('AppShell — pastille de notifications non lues (Lot 12c)', () => {
  beforeEach(() => vi.clearAllMocks())

  it('candidat avec des non-lues : la pastille affiche le compteur sur le lien Notifications', async () => {
    apiClient.get.mockResolvedValue({ data: { non_lues: 3 } })
    useAuth.mockReturnValue({ user: { profil: { prenom: 'Aya', nom: 'T' } }, role: 'candidat', logout: vi.fn() })
    render(<MemoryRouter><AppShell title="t">contenu</AppShell></MemoryRouter>)

    await waitFor(() => expect(apiClient.get).toHaveBeenCalledWith('/candidat/notifications/compteur'))
    expect(await screen.findByText('3')).toBeInTheDocument()
  })

  it('candidat sans non-lues : aucune pastille', async () => {
    apiClient.get.mockResolvedValue({ data: { non_lues: 0 } })
    useAuth.mockReturnValue({ user: { profil: { prenom: 'Aya', nom: 'T' } }, role: 'candidat', logout: vi.fn() })
    render(<MemoryRouter><AppShell title="t">contenu</AppShell></MemoryRouter>)

    await waitFor(() => expect(apiClient.get).toHaveBeenCalled())
    expect(screen.queryByText('0')).not.toBeInTheDocument()
  })

  it('évaluateur/admin : jamais appelé, jamais de pastille (l\'entrée n\'existe même pas dans leur nav)', () => {
    useAuth.mockReturnValue({ user: { profil: { prenom: 'Admin', nom: 'Test' } }, role: 'administrateur', logout: vi.fn() })
    render(<MemoryRouter><AppShell title="t">contenu</AppShell></MemoryRouter>)

    expect(apiClient.get).not.toHaveBeenCalled()
  })
})
