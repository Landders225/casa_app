import { act, renderHook } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { apiClient } from '../../../lib/apiClient.js'
import { useAffectationAutomatique } from '../useAffectationAutomatique.js'

vi.mock('../../../lib/apiClient.js', () => ({ apiClient: { post: vi.fn() } }))

afterEach(() => vi.clearAllMocks())

/**
 * `calculerApercu` est relancé à chaque changement de sélection/cible dans
 * la modale (ADR-45/48) — un aperçu plus ANCIEN peut répondre APRÈS un plus
 * récent (réseau). Correctif de revue : un compteur de requête doit ignorer
 * toute réponse qui n'est plus la dernière lancée.
 */
describe('useAffectationAutomatique — réponse d’aperçu périmée ignorée (correctif de revue)', () => {
  const corps = { criteres: {}, nombreCorrespondants: 10, evaluateurIds: null, cibles: null }

  it('la 1ʳᵉ requête, résolue APRÈS la 2ᵉ, n’écrase pas l’aperçu de la 2ᵉ', async () => {
    const resolveurs = []
    apiClient.post.mockImplementation(() => new Promise((resolve) => resolveurs.push(resolve)))
    const { result } = renderHook(() => useAffectationAutomatique())

    act(() => { result.current.calculerApercu(corps) })
    act(() => { result.current.calculerApercu(corps) })
    expect(resolveurs).toHaveLength(2)

    // La 2ᵉ (dernière) requête lancée résout D'ABORD.
    await act(async () => { resolveurs[1]({ data: { nombre_affectables: 20 } }) })
    expect(result.current.apercu).toEqual({ nombre_affectables: 20 })
    expect(result.current.chargement).toBe(false)

    // ...puis la 1ʳᵉ (désormais périmée) résout ENSUITE : ignorée, l'aperçu
    // affiché reste celui de la 2ᵉ requête, jamais réécrasé par du périmé.
    await act(async () => { resolveurs[0]({ data: { nombre_affectables: 999 } }) })
    expect(result.current.apercu).toEqual({ nombre_affectables: 20 })
    expect(result.current.chargement).toBe(false)
  })

  it('un échec de la 1ʳᵉ requête, survenant APRÈS le succès de la 2ᵉ, n’affiche pas d’erreur périmée', async () => {
    const resolveurs = []
    const rejeteurs = []
    apiClient.post
      .mockImplementationOnce(() => new Promise((_resolve, reject) => rejeteurs.push(reject)))
      .mockImplementationOnce(() => new Promise((resolve) => resolveurs.push(resolve)))
    const { result } = renderHook(() => useAffectationAutomatique())

    act(() => { result.current.calculerApercu(corps) })
    act(() => { result.current.calculerApercu(corps) })

    await act(async () => { resolveurs[0]({ data: { nombre_affectables: 20 } }) })
    expect(result.current.apercu).toEqual({ nombre_affectables: 20 })

    await act(async () => { rejeteurs[0](new Error('périmé')) })
    expect(result.current.apercu).toEqual({ nombre_affectables: 20 })
    expect(result.current.erreur).toBeNull()
  })
})
