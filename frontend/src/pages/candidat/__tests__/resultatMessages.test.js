import { describe, expect, it } from 'vitest'
import {
  MESSAGE_EN_TRAITEMENT,
  MESSAGE_NON_RETENU_GENERIQUE,
  SUIVI_ETAPES,
  messageResultat,
  suiviEtapeCourante,
} from '../resultatMessages.js'

/**
 * Ces fonctions sont le cœur de la règle reine côté UI : un `switch` PUR, aucune
 * dérivation. On vérifie ici qu'elles ne fabriquent jamais de contenu interne.
 */
const TERMES_INTERNES = /score|rang|bar[èe]me|[ée]ligib|[ée]valuat|\bnote\b|\/(35|65|100)|\bpoints?\b|pond[ée]r/i

describe('suiviEtapeCourante — 4 étapes, pilotées par statut_public SEUL', () => {
  it('a exactement 4 étapes, sans mention d’étape interne', () => {
    expect(SUIVI_ETAPES).toEqual(['Compte créé', 'Dossier soumis', 'En traitement', 'Résultat'])
    // pas d'« Entretien », « Évaluation », « Éligibilité », « Instruction »
    expect(SUIVI_ETAPES.join(' ')).not.toMatch(/entretien|[ée]valuation|[ée]ligibilit|instruction/i)
  })

  it.each([
    ['brouillon', 1],
    ['en_cours_de_traitement', 2],
    ['decision_publiee', 4],
    [null, 1],
    ['valeur_inconnue', 1],
  ])('%s -> étape courante %i', (statut, attendu) => {
    expect(suiviEtapeCourante(statut)).toBe(attendu)
  })
})

describe('messageResultat — switch pur sur decision', () => {
  it('retenu : félicitations + filière, aucun terme interne', () => {
    const m = messageResultat('retenu', { filiereNom: 'Agent de cuisine' })
    expect(m.variant).toBe('success')
    expect(m.titre).toMatch(/félicitations/i)
    expect(m.corps).toContain('Agent de cuisine')
    expect(m.corps).not.toMatch(TERMES_INTERNES)
  })

  it('liste_attente : message d’attente', () => {
    const m = messageResultat('liste_attente', { filiereNom: 'Buanderie' })
    expect(m.variant).toBe('warning')
    expect(m.titre).toMatch(/liste d'attente/i)
    expect(m.corps).not.toMatch(TERMES_INTERNES)
  })

  it('indisponible : clôture, cause neutre', () => {
    const m = messageResultat('indisponible', {})
    expect(m.titre).toMatch(/clôturée/i)
    expect(m.corps).toMatch(/n'a pas pu être maintenue/i)
    // ne dit NI « désistement » NI « décision » — neutre sur la cause
    expect(m.corps).not.toMatch(/désist|décision|sanction/i)
    expect(m.corps).not.toMatch(TERMES_INTERNES)
  })

  it('non_retenu SANS motif : message générique FIXE (constante client)', () => {
    const m = messageResultat('non_retenu', { motif: null })
    expect(m.motif).toBeUndefined()
    expect(m.corps).toBe(MESSAGE_NON_RETENU_GENERIQUE)
  })

  it('non_retenu AVEC motif : le motif prime, jamais le générique', () => {
    const m = messageResultat('non_retenu', { motif: '  Places limitées cette année.  ' })
    expect(m.motif).toBe('Places limitées cette année.')
  })

  it('decision inattendue (null / inconnue) -> repli défensif = non_retenu générique', () => {
    for (const d of [null, undefined, 'wat', '']) {
      const m = messageResultat(d, {})
      expect(m.titre).toMatch(/n'a pas été retenue/i)
      expect(m.corps).toBe(MESSAGE_NON_RETENU_GENERIQUE)
    }
  })

  it('MESSAGE_EN_TRAITEMENT est une phrase neutre, sans indice de traitement interne', () => {
    expect(MESSAGE_EN_TRAITEMENT.corps).not.toMatch(TERMES_INTERNES)
    expect(MESSAGE_EN_TRAITEMENT.corps).not.toMatch(/[ée]valuateur|jury|commission|instruction/i)
  })
})
