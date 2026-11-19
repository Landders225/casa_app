import { describe, expect, it } from 'vitest'
import { calculerRetablissementDesactive } from '../useRetablissementEligibilite.js'

/**
 * Lot 4 (ADR-49) — calcul EN DIRECT (pas d'appel serveur) des 3 conditions de
 * désactivation du bouton « Rendre éligible ». Fonction pure extraite pour ce
 * test : le cas « plafond dépassé » demanderait 501 cases cochées une à une
 * en test UI, irréaliste pour une vérification de l'opérateur `>`.
 */
describe('calculerRetablissementDesactive', () => {
  it('rien de bloquant -> activé', () => {
    expect(calculerRetablissementDesactive({ selectionTousActif: false, contientDejaEligible: false, taille: 3 }))
      .toEqual({ desactive: false, titre: undefined })
  })

  it('selectionTous actif -> désactivé, prioritaire sur les autres cas', () => {
    const r = calculerRetablissementDesactive({ selectionTousActif: true, contientDejaEligible: true, taille: 9999 })
    expect(r.desactive).toBe(true)
    expect(r.titre).toMatch(/un par un/i)
  })

  it('sélection contenant un dossier déjà éligible -> désactivé', () => {
    const r = calculerRetablissementDesactive({ selectionTousActif: false, contientDejaEligible: true, taille: 2 })
    expect(r.desactive).toBe(true)
    expect(r.titre).toMatch(/déjà éligible/i)
  })

  it('exactement au plafond (500) -> activé (la limite est ">", pas ">=")', () => {
    const r = calculerRetablissementDesactive({ selectionTousActif: false, contientDejaEligible: false, taille: 500 })
    expect(r.desactive).toBe(false)
  })

  it('un seul au-delà du plafond (501) -> désactivé', () => {
    const r = calculerRetablissementDesactive({ selectionTousActif: false, contientDejaEligible: false, taille: 501 })
    expect(r.desactive).toBe(true)
    expect(r.titre).toMatch(/500 dossiers/)
  })

  it('plafond personnalisé respecté', () => {
    expect(calculerRetablissementDesactive({ selectionTousActif: false, contientDejaEligible: false, taille: 10, plafond: 5 }).desactive).toBe(true)
    expect(calculerRetablissementDesactive({ selectionTousActif: false, contientDejaEligible: false, taille: 5, plafond: 5 }).desactive).toBe(false)
  })
})
