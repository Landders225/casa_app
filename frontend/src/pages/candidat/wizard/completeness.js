import { stepIndex } from './formStructure.js'

/**
 * Complétude CÔTÉ CLIENT — miroir de App\Domain\Candidature\ValidateurCompletude.
 * Contrôle LÉGER : bloque « Suivant » sur une étape manifestement incomplète et
 * signale les champs. L'AUTORITÉ reste le 422 de `POST /soumettre` (backend).
 *
 * `reponses` : miroir local des colonnes `reponse_formulaire` (valeurs ou null).
 * `experiences` : [{ domaine, duree_categorie, justificatif }].
 * `pieces` : { [type_document_code]: pièce | undefined }.
 */

const LETTRE_MIN = 30

const filled = (v) => v !== null && v !== undefined && v !== ''
const niveauSet = (v) => v !== null && v !== undefined && v >= 0

/** Champs manquants d'une étape -> { champ: message }. Vide = complète. */
export function stepErrors(key, { profil, candidature, reponses, experiences, pieces }) {
  const e = {}
  const req = (field, cond = true, msg = 'Cette réponse est obligatoire.') => {
    if (cond && !filled(reponses[field])) e[field] = msg
  }

  switch (key) {
    case 'identite':
      for (const f of ['prenom', 'nom', 'date_naissance', 'cni']) {
        if (!filled(profil?.[f])) e[f] = 'Information obligatoire.'
      }
      break

    case 'filiere':
      if (!candidature?.cqp_confirme) e.cqp_confirme = 'Confirmez votre filière pour continuer.'
      break

    case 'scolaire': {
      req('sc01_scolarise_actuellement')
      req('sc02_derniere_classe')
      req('sc03_document_justifiant_niveau')
      req('sc05_beneficiaire_formation_actuelle')
      if (reponses.sc05_beneficiaire_formation_actuelle === 'non') {
        req('sc06_deja_beneficie_formation')
        if (reponses.sc06_deja_beneficie_formation === 'oui') {
          req('sc07_filiere_suivie')
          req('sc08_mene_a_terme')
          if (reponses.sc08_mene_a_terme === 'non') req('sc09_motif_non_achevement')
        }
      }
      break
    }

    case 'socioEco':
      req('se02_orphelin')
      req('se03_situation_emploi')
      req('se06_soutien_menage')
      req('se04_source_revenu', reponses.se03_situation_emploi === 'sans_emploi')
      break

    case 'experience':
      experiences.forEach((exp, i) => {
        if (!filled(exp.domaine)) e[`experiences.${i}.domaine`] = `Domaine manquant (expérience ${i + 1}).`
        if (!filled(exp.duree_categorie)) e[`experiences.${i}.duree_categorie`] = `Durée manquante (expérience ${i + 1}).`
        if (!exp.justificatif) e[`experiences.${i}.justificatif`] = `Justificatif manquant (expérience ${i + 1}).`
      })
      break

    case 'langues':
      for (const f of ['langue_ecrit', 'langue_parle', 'langue_comprehension', 'info_word', 'info_excel', 'info_internet']) {
        if (!niveauSet(reponses[f])) e[f] = 'Évaluez ce niveau.'
      }
      break

    case 'motivation': {
      const lettre = (reponses.mo04_lettre_motivation || '').trim()
      if (lettre.length < LETTRE_MIN) {
        e.mo04_lettre_motivation = `Détaillez un peu plus votre motivation (au moins ${LETTRE_MIN} caractères).`
      }
      break
    }

    case 'disponibilite':
      req('di01_disponible_lun_ven')
      req('di02_contraintes')
      req('di03_engagement_complet')
      req('acces_plateau')
      req('acces_deux_plateaux_vallons')
      break

    case 'documents': {
      const manquants = ['cni', 'residence', 'diplome', 'cv', 'lettre', 'photo'].filter((t) => !pieces[t])
      if (manquants.length) e.pieces_dossier = `${manquants.length} pièce(s) restante(s) à déposer.`
      break
    }

    default:
      break
  }

  return e
}

/**
 * Clé d'erreur renvoyée par `POST /soumettre` -> index d'étape à corriger.
 * Ex. « reponses.sc01_scolarise_actuellement » -> 2 ; « experiences.0.justificatif » -> 4.
 */
export function submitErrorStep(key) {
  if (key.startsWith('identite.')) return stepIndex('identite')
  if (key === 'cqp_confirme') return stepIndex('filiere')
  if (key.startsWith('experiences.')) return stepIndex('experience')
  if (key === 'pieces_dossier') return stepIndex('documents')

  const field = key.replace(/^reponses\./, '')
  if (field.startsWith('sc0')) return stepIndex('scolaire')
  if (field.startsWith('se0')) return stepIndex('socioEco')
  if (field.startsWith('langue_') || field.startsWith('info_')) return stepIndex('langues')
  if (field === 'mo04_lettre_motivation') return stepIndex('motivation')
  if (field.startsWith('di0') || field.startsWith('acces_')) return stepIndex('disponibilite')
  return stepIndex('recap')
}

/** Libellé court d'une clé d'erreur de soumission (pour la liste « à corriger »). */
export function submitErrorLabel(key) {
  const known = {
    'identite.prenom': 'Prénom',
    'identite.nom': 'Nom',
    'identite.date_naissance': 'Date de naissance',
    'identite.cni': 'Numéro CNI / récépissé',
    cqp_confirme: 'Confirmation de la filière',
    pieces_dossier: 'Pièces justificatives',
  }
  if (known[key]) return known[key]
  if (key.startsWith('experiences.')) {
    const [, i, champ] = key.split('.')
    const nom = { domaine: 'domaine', duree_categorie: 'durée', justificatif: 'justificatif' }[champ] || champ
    return `Expérience ${Number(i) + 1} — ${nom}`
  }
  return key.replace(/^reponses\./, '').replace(/_/g, ' ')
}
