import { describe, expect, it } from 'vitest'
import { stepErrors, submitErrorLabel, submitErrorStep } from '../completeness.js'
import { stepIndex } from '../formStructure.js'

const base = { profil: {}, candidature: null, reponses: {}, experiences: [], pieces: {} }

describe('stepErrors', () => {
  it('scolaire : signale les 4 champs de base manquants', () => {
    const e = stepErrors('scolaire', base)
    expect(Object.keys(e)).toEqual(expect.arrayContaining([
      'sc01_scolarise_actuellement', 'sc02_derniere_classe',
      'sc03_document_justifiant_niveau', 'sc05_beneficiaire_formation_actuelle',
    ]))
  })

  it('scolaire : conditionnels SC.06 → SC.07/SC.08 → SC.09', () => {
    const r = {
      sc01_scolarise_actuellement: 'non', sc02_derniere_classe: '3e',
      sc03_document_justifiant_niveau: 'oui', sc05_beneficiaire_formation_actuelle: 'non',
      sc06_deja_beneficie_formation: 'oui', sc08_mene_a_terme: 'non',
    }
    const e = stepErrors('scolaire', { ...base, reponses: r })
    expect(e).toHaveProperty('sc07_filiere_suivie')
    expect(e).toHaveProperty('sc09_motif_non_achevement')
  })

  it('socioEco : SE.04 requis seulement si sans emploi', () => {
    const complet = { se02_orphelin: 'non', se03_situation_emploi: 'stage', se06_soutien_menage: 'non' }
    expect(stepErrors('socioEco', { ...base, reponses: complet })).toEqual({})

    const sansEmploi = { ...complet, se03_situation_emploi: 'sans_emploi' }
    expect(stepErrors('socioEco', { ...base, reponses: sansEmploi })).toHaveProperty('se04_source_revenu')
  })

  it('langues : les 6 niveaux, 0 est une valeur valide', () => {
    const r = {
      langue_ecrit: 0, langue_parle: 2, langue_comprehension: 3,
      info_word: 1, info_excel: 0, info_internet: 2,
    }
    expect(stepErrors('langues', { ...base, reponses: r })).toEqual({})
    expect(stepErrors('langues', { ...base, reponses: { ...r, info_excel: null } })).toHaveProperty('info_excel')
  })

  it('motivation : lettre < 30 caractères', () => {
    expect(stepErrors('motivation', { ...base, reponses: { mo04_lettre_motivation: 'trop court' } }))
      .toHaveProperty('mo04_lettre_motivation')
    expect(stepErrors('motivation', { ...base, reponses: { mo04_lettre_motivation: 'x'.repeat(30) } })).toEqual({})
  })

  it('experience : domaine + durée + justificatif par ligne', () => {
    const e = stepErrors('experience', { ...base, experiences: [{ domaine: 'hotellerie', duree_categorie: '', justificatif: null }] })
    expect(e).toHaveProperty('experiences.0.duree_categorie')
    expect(e).toHaveProperty('experiences.0.justificatif')
  })

  it('documents : 6 pièces obligatoires', () => {
    expect(stepErrors('documents', { ...base, pieces: {} })).toHaveProperty('pieces_dossier')
    const toutes = { cni: 1, residence: 1, diplome: 1, cv: 1, lettre: 1, photo: 1 }
    expect(stepErrors('documents', { ...base, pieces: toutes })).toEqual({})
  })

  it('filiere : cqp_confirme requis', () => {
    expect(stepErrors('filiere', base)).toHaveProperty('cqp_confirme')
    expect(stepErrors('filiere', { ...base, candidature: { cqp_confirme: true } })).toEqual({})
  })

  it('identite : prenom/nom/date/cni sur le profil', () => {
    expect(Object.keys(stepErrors('identite', base))).toEqual(['prenom', 'nom', 'date_naissance', 'cni'])
  })
})

describe('submitErrorStep — mapping clé serveur → étape', () => {
  it.each([
    ['identite.prenom', 'identite'],
    ['cqp_confirme', 'filiere'],
    ['reponses.sc01_scolarise_actuellement', 'scolaire'],
    ['reponses.se06_soutien_menage', 'socioEco'],
    ['experiences.0.justificatif', 'experience'],
    ['reponses.langue_ecrit', 'langues'],
    ['reponses.info_word', 'langues'],
    ['reponses.mo04_lettre_motivation', 'motivation'],
    ['reponses.di01_disponible_lun_ven', 'disponibilite'],
    ['reponses.acces_plateau', 'disponibilite'],
    ['pieces_dossier', 'documents'],
  ])('%s → %s', (key, stepKey) => {
    expect(submitErrorStep(key)).toBe(stepIndex(stepKey))
  })
})

describe('submitErrorLabel', () => {
  it('libellés lisibles', () => {
    expect(submitErrorLabel('identite.cni')).toBe('Numéro CNI / récépissé')
    expect(submitErrorLabel('experiences.1.justificatif')).toBe('Expérience 2 — justificatif')
    expect(submitErrorLabel('pieces_dossier')).toBe('Pièces justificatives')
  })
})
