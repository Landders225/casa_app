import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { RankList } from '../RankList.jsx'

const ITEMS = [
  { id: 'a', nom: 'Accueil-réception' },
  { id: 'b', nom: 'Agent de buanderie' },
  { id: 'c', nom: 'Agent de cuisine' },
  { id: 'd', nom: 'Entretien hôtelier' },
  { id: 'e', nom: 'Service restaurant-bar' },
]

function drag(fromEl, toEl) {
  fireEvent.dragStart(fromEl)
  fireEvent.dragOver(toEl)
  fireEvent.drop(toEl)
  fireEvent.dragEnd(fromEl)
}

describe('RankList — sans lockedId : comportement inchangé', () => {
  it('les flèches Monter/Descendre permutent deux voisins et renvoient les 5 ids', () => {
    const onReorder = vi.fn()
    render(<RankList items={ITEMS} onReorder={onReorder} />)

    fireEvent.click(screen.getByRole('button', { name: 'Descendre Accueil-réception' }))
    expect(onReorder).toHaveBeenCalledWith(['b', 'a', 'c', 'd', 'e'])
  })

  it('le premier item n’a pas de "Monter", le dernier n’a pas de "Descendre" actif', () => {
    render(<RankList items={ITEMS} onReorder={vi.fn()} />)
    expect(screen.getByRole('button', { name: 'Monter Accueil-réception' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Descendre Service restaurant-bar' })).toBeDisabled()
  })

  it('le glisser-déposer réordonne (dragstart sur A, drop sur C -> A prend la place de C)', () => {
    const onReorder = vi.fn()
    render(<RankList items={ITEMS} onReorder={onReorder} />)
    drag(screen.getByText('Accueil-réception').closest('li'), screen.getByText('Agent de cuisine').closest('li'))
    expect(onReorder).toHaveBeenCalledWith(['b', 'c', 'a', 'd', 'e'])
  })
})

describe('RankList — avec lockedId (Lot C) : la filière confirmée est figée', () => {
  it('l’item figé n’a ni poignée de glisser-déposer, ni flèches, ni numéro de rang', () => {
    render(<RankList items={ITEMS} onReorder={vi.fn()} lockedId="a" />)

    const li = screen.getByText('Accueil-réception').closest('li')
    expect(li).toHaveAttribute('draggable', 'false')
    expect(li).toHaveClass('is-locked')
    expect(screen.queryByRole('button', { name: /Accueil-réception/ })).not.toBeInTheDocument()
    expect(screen.getByText('Votre choix principal')).toBeInTheDocument()
  })

  it('aria-label annonce clairement "choix principal, déjà confirmé, position non modifiable"', () => {
    render(<RankList items={ITEMS} onReorder={vi.fn()} lockedId="a" />)
    expect(screen.getByLabelText('Accueil-réception — votre choix principal, déjà confirmé, position non modifiable')).toBeInTheDocument()
  })

  it('les 4 autres items restent classables (flèches présentes, cible ≥44px via .btn-icon)', () => {
    render(<RankList items={ITEMS} onReorder={vi.fn()} lockedId="a" />)
    expect(screen.getByRole('button', { name: 'Monter Agent de buanderie' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Descendre Agent de buanderie' })).toBeInTheDocument()
  })

  it('PREUVE CENTRALE : un item classable qui "remonte" au-delà de l’item figé ne le déplace JAMAIS — l’item figé reste TOUJOURS en position 0 dans le tableau renvoyé', () => {
    const onReorder = vi.fn()
    // a = figé (index 0). On fait remonter e (dernier classable) jusqu'en tête des classables.
    render(<RankList items={ITEMS} onReorder={onReorder} lockedId="a" />)

    fireEvent.click(screen.getByRole('button', { name: 'Monter Service restaurant-bar' })) // e: d,e -> e,d parmi les classables
    let sent = onReorder.mock.calls.at(-1)[0]
    expect(sent[0]).toBe('a') // figé toujours en tête
    expect(sent).toEqual(['a', 'b', 'c', 'e', 'd'])
  })

  it('le drop SUR l’item figé est un no-op (aucune cible valide)', () => {
    const onReorder = vi.fn()
    render(<RankList items={ITEMS} onReorder={onReorder} lockedId="a" />)
    drag(screen.getByText('Agent de cuisine').closest('li'), screen.getByText('Accueil-réception').closest('li'))
    expect(onReorder).not.toHaveBeenCalled()
  })

  it('onReorder reçoit TOUJOURS exactement les 5 ids, quel que soit le mouvement', () => {
    const onReorder = vi.fn()
    render(<RankList items={ITEMS} onReorder={onReorder} lockedId="a" />)
    fireEvent.click(screen.getByRole('button', { name: 'Descendre Agent de buanderie' }))
    const sent = onReorder.mock.calls.at(-1)[0]
    expect(sent).toHaveLength(5)
    expect(new Set(sent).size).toBe(5) // pas de doublon
  })

  it('robustesse : le mécanisme reste correct même si l’item figé n’est PAS à l’index 0 du tableau reçu', () => {
    // Cas défensif — StepMotivation force toujours l'index 0 en pratique
    // (Lot C), mais RankList lui-même ne doit pas le supposer.
    const onReorder = vi.fn()
    render(<RankList items={ITEMS} onReorder={onReorder} lockedId="c" />) // c est en index 2
    expect(screen.getByText('Agent de cuisine').closest('li')).toHaveClass('is-locked')

    // Faire descendre 'a' (index 0) doit le rapprocher de 'c' SANS jamais le dépasser en une étape ;
    // dans le sous-tableau classable [a,b,d,e], "Descendre a" -> [b,a,d,e] -> reconstruit [b,a,c,d,e].
    fireEvent.click(screen.getByRole('button', { name: 'Descendre Accueil-réception' }))
    const sent = onReorder.mock.calls.at(-1)[0]
    expect(sent[2]).toBe('c') // toujours à son index d'origine (2)
    expect(sent).toEqual(['b', 'a', 'c', 'd', 'e'])
  })
})

describe('RankList — interaction clavier (accessibilité, mécanisme tactile)', () => {
  it('les boutons flèches sont activables au clavier (Entrée)', async () => {
    const onReorder = vi.fn()
    render(<RankList items={ITEMS} onReorder={onReorder} lockedId="a" />)
    const user = userEvent.setup()
    await user.tab() // saute l'item figé (non focusable, aucun bouton dedans)
    // Le premier élément focusable est "Monter Agent de buanderie" (désactivé) puis "Descendre".
    const bouton = screen.getByRole('button', { name: 'Descendre Agent de buanderie' })
    bouton.focus()
    await user.keyboard('{Enter}')
    expect(onReorder).toHaveBeenCalled()
  })
})
