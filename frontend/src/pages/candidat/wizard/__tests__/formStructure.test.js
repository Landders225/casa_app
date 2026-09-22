import { describe, expect, it } from 'vitest'
import * as structure from '../formStructure.js'
import { DISPONIBILITE, PIECES_DOSSIER, PIECES_DOSSIER_RETIREES, SCOLAIRE, SOCIO_ECO, STEPS } from '../formStructure.js'

/**
 * CONFIDENTIALITÉ (ADR-02) — formStructure.js est LE fichier le plus à risque de
 * fuite (transcription de CASA_GRILLE). Il ne doit contenir AUCUN point / aucune
 * pondération / aucun maximum de volet.
 */
describe('formStructure — non-fuite du barème', () => {
  const dump = JSON.stringify(structure)

  it('ne contient aucun terme de barème', () => {
    for (const interdit of [
      '/65', '/35', '/100', 'sur 100', 'sur 65', 'sur 35',
      'pointsParEtoile', 'pondération', 'pondérée', 'pondéré',
      'sous-critère', 'sous-note', 'barème', 'bareme',
    ]) {
      expect(dump.toLowerCase(), `« ${interdit} » ne doit pas apparaître`).not.toContain(interdit.toLowerCase())
    }
  })

  it('aucune clé numérique de type "points" / "max" / "poids"', () => {
    const walk = (obj) => {
      if (Array.isArray(obj)) return obj.forEach(walk)
      if (obj && typeof obj === 'object') {
        for (const k of Object.keys(obj)) {
          expect(['points', 'max', 'poids', 'pointsParEtoile', 'notationEvaluateur']).not.toContain(k)
          walk(obj[k])
        }
      }
    }
    walk(structure)
  })

  it('aucune mention de « point » ou « pt » comme unité', () => {
    expect(/\bpts?\b/i.test(dump)).toBe(false)
    expect(/\bpoints?\b/i.test(dump)).toBe(false)
  })
})

describe('formStructure — contrat backend', () => {
  it('10 étapes dans le bon ordre', () => {
    expect(STEPS.map((s) => s.key)).toEqual([
      'identite', 'filiere', 'scolaire', 'socioEco', 'experience',
      'langues', 'motivation', 'disponibilite', 'documents', 'recap',
    ])
  })

  it('les `field` correspondent aux colonnes reponse_formulaire', () => {
    expect(SCOLAIRE.sc01.field).toBe('sc01_scolarise_actuellement')
    expect(SCOLAIRE.sc02.options.map((o) => o.value)).toEqual(
      ['avant_3e', 'cap', '3e', 'seconde', '1ere', 'terminale', 'bt_bep'],
    )
    expect(SOCIO_ECO.se03.options.map((o) => o.value)).toEqual(
      ['sans_emploi', 'stage', 'interim', 'temps_partiel', 'temps_plein'],
    )
    expect(DISPONIBILITE.di04.field).toBe('acces_plateau')
    expect(DISPONIBILITE.di05.field).toBe('acces_deux_plateaux_vallons')
  })

  it('les 5 pièces obligatoires du dossier (Lot D : residence/lettre retirées)', () => {
    expect(PIECES_DOSSIER.map((p) => p.code)).toEqual(['cni', 'diplome', 'cv', 'photo', 'cmu'])
  })

  it('les 2 pièces retirées restent connues, à part (affichage lecture seule uniquement)', () => {
    expect(PIECES_DOSSIER_RETIREES.map((p) => p.code)).toEqual(['residence', 'lettre'])
    // Jamais dans la liste obligatoire.
    for (const p of PIECES_DOSSIER_RETIREES) {
      expect(PIECES_DOSSIER.map((x) => x.code)).not.toContain(p.code)
    }
  })

  it('ne propose jamais SC.04 / note en étoiles / nationalité', () => {
    expect(structure.SCOLAIRE.sc04).toBeUndefined()
    expect(dumpKeys()).not.toContain('mo04_note_etoiles')
    expect(dumpKeys()).not.toContain('nationalite')
    function dumpKeys() {
      return JSON.stringify(structure)
    }
  })
})
