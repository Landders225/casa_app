import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../../auth/useAuth.js'
import { AppShell } from '../AppShell.jsx'

vi.mock('../../../auth/useAuth.js', () => ({ useAuth: vi.fn() }))

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
