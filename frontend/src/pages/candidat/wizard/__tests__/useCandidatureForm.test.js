import { describe, expect, it } from 'vitest'
import { classementFromResource } from '../useCandidatureForm.js'

const FILIERES = [
  { id: 'f-acc', nom: 'Accueil-réception' },
  { id: 'f-bua', nom: 'Agent de buanderie' },
  { id: 'f-cui', nom: 'Agent de cuisine' },
  { id: 'f-ent', nom: 'Entretien hôtelier' },
  { id: 'f-res', nom: 'Service restaurant-bar' },
]

describe('classementFromResource — Lot C : la filière confirmée est TOUJOURS en tête', () => {
  it('repli alphabétique (aucun classement enregistré) : la confirmée passe en position 1 même si elle ne serait pas 1ère alphabétiquement', () => {
    // Alphabétiquement, "Agent de cuisine" (f-cui) serait en position 3.
    const out = classementFromResource({ filiere: { id: 'f-cui' }, classement: [] }, FILIERES)
    expect(out[0].id).toBe('f-cui')
    expect(out).toHaveLength(5)
    // Les 4 autres gardent leur ordre alphabétique relatif.
    expect(out.slice(1).map((f) => f.id)).toEqual(['f-acc', 'f-bua', 'f-ent', 'f-res'])
  })

  it('classement déjà sauvegardé où la confirmée n’est PAS en tête (dossier repris) : replacée en 1ère position, les autres gardent leur ordre relatif', () => {
    const res = {
      filiere: { id: 'f-cui' },
      classement: [
        { rang: 1, filiere: { id: 'f-res', nom: 'Service restaurant-bar' } },
        { rang: 2, filiere: { id: 'f-acc', nom: 'Accueil-réception' } },
        { rang: 3, filiere: { id: 'f-cui', nom: 'Agent de cuisine' } }, // confirmée, PAS en tête
        { rang: 4, filiere: { id: 'f-bua', nom: 'Agent de buanderie' } },
        { rang: 5, filiere: { id: 'f-ent', nom: 'Entretien hôtelier' } },
      ],
    }
    const out = classementFromResource(res, FILIERES)
    expect(out.map((f) => f.id)).toEqual(['f-cui', 'f-res', 'f-acc', 'f-bua', 'f-ent'])
  })

  it('classement déjà sauvegardé où la confirmée est DÉJÀ en tête : inchangé', () => {
    const res = {
      filiere: { id: 'f-cui' },
      classement: [
        { rang: 1, filiere: { id: 'f-cui', nom: 'Agent de cuisine' } },
        { rang: 2, filiere: { id: 'f-acc', nom: 'Accueil-réception' } },
        { rang: 3, filiere: { id: 'f-bua', nom: 'Agent de buanderie' } },
        { rang: 4, filiere: { id: 'f-ent', nom: 'Entretien hôtelier' } },
        { rang: 5, filiere: { id: 'f-res', nom: 'Service restaurant-bar' } },
      ],
    }
    const out = classementFromResource(res, FILIERES)
    expect(out.map((f) => f.id)).toEqual(['f-cui', 'f-acc', 'f-bua', 'f-ent', 'f-res'])
  })

  it('aucune filière confirmée (res absent / filiere absente) : ordre inchangé, pas d’erreur', () => {
    expect(classementFromResource(null, FILIERES).map((f) => f.id)).toEqual(['f-acc', 'f-bua', 'f-cui', 'f-ent', 'f-res'])
    expect(classementFromResource({ filiere: null, classement: [] }, FILIERES).map((f) => f.id))
      .toEqual(['f-acc', 'f-bua', 'f-cui', 'f-ent', 'f-res'])
  })
})
