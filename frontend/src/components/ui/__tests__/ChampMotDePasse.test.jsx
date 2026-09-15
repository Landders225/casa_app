import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { ChampMotDePasse } from '../ChampMotDePasse.jsx'

describe('ChampMotDePasse — bascule afficher/masquer', () => {
  it('masqué par défaut (type="password"), le bouton annonce "Afficher"', () => {
    render(<ChampMotDePasse label="Mot de passe" inputProps={{ value: 'secret', onChange: vi.fn() }} />)

    expect(screen.getByLabelText('Mot de passe')).toHaveAttribute('type', 'password')
    expect(screen.getByRole('button', { name: 'Afficher le mot de passe' })).toBeInTheDocument()
  })

  it('un clic bascule vers type="text" et l\'aria-label annonce "Masquer"', async () => {
    render(<ChampMotDePasse label="Mot de passe" inputProps={{ value: 'secret', onChange: vi.fn() }} />)
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'Afficher le mot de passe' }))

    expect(screen.getByLabelText('Mot de passe')).toHaveAttribute('type', 'text')
    expect(screen.getByRole('button', { name: 'Masquer le mot de passe' })).toBeInTheDocument()

    // Re-clic : retour à l'état masqué.
    await user.click(screen.getByRole('button', { name: 'Masquer le mot de passe' }))
    expect(screen.getByLabelText('Mot de passe')).toHaveAttribute('type', 'password')
  })

  it('activable au clavier (Entrée ET Espace), sans souris', async () => {
    render(<ChampMotDePasse label="Mot de passe" inputProps={{ value: 'secret', onChange: vi.fn() }} />)
    const user = userEvent.setup()

    await user.tab() // le champ input
    await user.tab() // le bouton œil
    expect(screen.getByRole('button', { name: 'Afficher le mot de passe' })).toHaveFocus()

    await user.keyboard('{Enter}')
    expect(screen.getByLabelText('Mot de passe')).toHaveAttribute('type', 'text')

    await user.keyboard(' ')
    expect(screen.getByLabelText('Mot de passe')).toHaveAttribute('type', 'password')
  })

  it('le bouton est type="button" — ne déclenche PAS la soumission du formulaire englobant', async () => {
    const onSubmit = vi.fn((e) => e.preventDefault())
    render(
      <form onSubmit={onSubmit}>
        <ChampMotDePasse label="Mot de passe" inputProps={{ value: 'secret', onChange: vi.fn() }} />
      </form>,
    )
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'Afficher le mot de passe' }))

    expect(onSubmit).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: 'Masquer le mot de passe' })).toHaveAttribute('type', 'button')
  })

  it('value/onChange restent transparents (le composant ne gère QUE l\'affichage)', async () => {
    const onChange = vi.fn()
    render(<ChampMotDePasse label="Mot de passe" inputProps={{ value: '', onChange }} />)
    const user = userEvent.setup()

    await user.type(screen.getByLabelText('Mot de passe'), 'A')

    expect(onChange).toHaveBeenCalled()
  })

  it('erreur et indication restent affichées, comme un FormField ordinaire', () => {
    render(
      <ChampMotDePasse
        label="Mot de passe"
        error="Trop court."
        hint="Au moins 10 caractères."
        inputProps={{ value: '', onChange: vi.fn() }}
      />,
    )

    expect(screen.getByText('Trop court.')).toBeInTheDocument()
    expect(screen.getByText('Au moins 10 caractères.')).toBeInTheDocument()
  })
})
