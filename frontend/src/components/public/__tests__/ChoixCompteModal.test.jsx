import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { ChoixCompteModal } from '../ChoixCompteModal.jsx'

function renderModal(onClose = vi.fn()) {
  return {
    onClose,
    ...render(
      <MemoryRouter>
        <button type="button">déclencheur</button>
        <ChoixCompteModal onClose={onClose} />
      </MemoryRouter>,
    ),
  }
}

describe('ChoixCompteModal (Lot B correctif)', () => {
  it('aria correct : role=dialog, aria-modal, labelledby/describedby pointent vers le titre et le corps', () => {
    renderModal()
    const dialog = screen.getByRole('dialog')
    expect(dialog).toHaveAttribute('aria-modal', 'true')

    const titreId = dialog.getAttribute('aria-labelledby')
    const descId = dialog.getAttribute('aria-describedby')
    expect(document.getElementById(titreId)).toHaveTextContent('Avez-vous déjà un compte CASA ?')
    expect(document.getElementById(descId)).toHaveTextContent(/connectez-vous pour continuer/i)
  })

  it('le focus part sur la modale à l’ouverture', () => {
    renderModal()
    expect(document.activeElement).toBe(screen.getByRole('dialog'))
  })

  it('la fermeture restaure le focus sur l’élément précédemment focus', async () => {
    const user = userEvent.setup()
    const onClose = vi.fn()
    const { rerender } = render(
      <MemoryRouter>
        <button type="button">déclencheur</button>
      </MemoryRouter>,
    )
    const declencheur = screen.getByRole('button', { name: 'déclencheur' })
    declencheur.focus()

    rerender(
      <MemoryRouter>
        <button type="button">déclencheur</button>
        <ChoixCompteModal onClose={onClose} />
      </MemoryRouter>,
    )
    expect(document.activeElement).toBe(screen.getByRole('dialog'))

    await user.keyboard('{Escape}')
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('appelle onClose sur Échap, sans naviguer', async () => {
    const { onClose } = renderModal()
    await userEvent.setup().keyboard('{Escape}')
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('appelle onClose au clic sur l’overlay (hors contenu)', async () => {
    const { onClose } = renderModal()
    const overlay = screen.getByRole('dialog').parentElement
    await userEvent.setup().click(overlay)
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('un clic à l’intérieur de la modale ne la ferme PAS', async () => {
    const { onClose } = renderModal()
    await userEvent.setup().click(screen.getByText(/connectez-vous pour continuer/i))
    expect(onClose).not.toHaveBeenCalled()
  })
})
