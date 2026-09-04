/**
 * ==========================================================================
 * SUIVI & RÉSULTAT CANDIDAT — textes et mappings (Lot 8b-3).
 * ==========================================================================
 *
 * ⚠️ RÈGLE REINE (ADR-03, contexte §14-17) — CE FICHIER NE DÉRIVE RIEN.
 *
 * L'UI candidat n'affiche QUE ce que `GET /api/candidature` renvoie :
 * `statut_public` (brouillon | en_cours_de_traitement | decision_publiee),
 * `decision` (null avant publication ; sinon retenu | liste_attente |
 * non_retenu | indisponible) et `motif_communicable` (null | texte admin).
 * `StatutPublicResolver` a déjà fait tout le travail de confidentialité côté
 * serveur : ni score, ni rang, ni `motif_interne`, ni `statut_interne`, ni le
 * moindre indice d'éligibilité n'atteint le frontend.
 *
 * Ici : un `switch` PUR sur ces valeurs → une constante de texte. Aucun calcul,
 * aucun seuil, aucune reconstruction. En particulier :
 *  - on NE reproduit PAS le timeline granulaire de `ma-candidature.html`
 *    (« Vérification d'éligibilité », « Instruction », « Évaluation »,
 *    « Entretien », badge « Non conforme ») : chaque étape trahirait la
 *    position interne dans le workflow. Le stepper ci-dessous a 4 états, pilotés
 *    par `statut_public` SEUL (D-8b3-1).
 *  - on NE reproduit PAS la bannière « Candidature non éligible » (l.78-79) :
 *    un `non_retenu` est un `non_retenu`, qu'il vienne d'un non-éligible interne
 *    ou d'un simple manque de places (D-5b-1, D-8b3-2).
 *
 * ⚠️ POINT OUVERT INSTITUTIONNEL : la formulation de tous les textes candidats
 * de ce fichier (résultats, non-retenue, clôture) relève d'une décision de
 * communication — à faire relire par les partenaires (CCI-CI / FADV / AICS)
 * avant mise en production, au même titre que les FAQ publiques du Lot 8b-1.
 */

/**
 * Message générique de non-retenue — affiché quand `motif_communicable` est
 * `null`. FIXE CÔTÉ CLIENT (ADR-03) : ce n'est jamais une donnée serveur.
 * Verbatim de `App_maquette/pages/candidate/ma-candidature.html` l.90 (branche
 * `else`).
 */
export const MESSAGE_NON_RETENU_GENERIQUE =
  "Le nombre de places étant limité, votre dossier n'a pas pu être retenu pour cette cohorte."

/** Bannière avant publication — une seule phrase neutre, pour TOUS les états
 *  internes (`soumis` / `en_instruction` / `non_eligible` / `evalue`). */
export const MESSAGE_EN_TRAITEMENT = {
  variant: 'info',
  titre: 'Candidature en cours de traitement',
  corps: "Votre dossier suit son cours. Le résultat vous sera communiqué à l'issue du processus de sélection.",
}

/** Suivi du dossier — 4 étapes, pilotées UNIQUEMENT par `statut_public`. */
export const SUIVI_ETAPES = ['Compte créé', 'Dossier soumis', 'En traitement', 'Résultat']

/**
 * Index (0-based) de l'étape COURANTE du stepper selon `statut_public`.
 * Les étapes d'index < courante sont « faites », l'étape courante est « active »,
 * les suivantes sont « à venir ». `decision_publiee` → tout est fait (index 4).
 */
export function suiviEtapeCourante(statutPublic) {
  switch (statutPublic) {
    case 'decision_publiee':
      return SUIVI_ETAPES.length // toutes faites
    case 'en_cours_de_traitement':
      return 2 // « En traitement »
    case 'brouillon':
    default:
      return 1 // « Dossier soumis » reste à faire
  }
}

/**
 * Bannière de résultat après publication — `switch` PUR sur `decision`.
 * `filiereNom` et `motif` (= `motif_communicable`) sont fournis par l'appelant,
 * jamais calculés ici.
 *
 * Retour : { variant, icone, titre, corps, motif? }
 *  - `corps` : le texte à afficher quand il n'y a pas de motif communicable.
 *  - `motif` : présent (chaîne non vide) UNIQUEMENT pour un `non_retenu` dont
 *    l'admin a saisi un motif communicable → l'appelant affiche « Motif : … »
 *    À LA PLACE de `corps`.
 *
 * `decision` inattendue (null, valeur inconnue) → repli défensif sur le message
 * générique de non-retenue, le plus neutre (D-8b3, Q6).
 */
export function messageResultat(decision, { filiereNom, motif } = {}) {
  const f = filiereNom || 'votre filière'

  switch (decision) {
    case 'retenu':
      return {
        variant: 'success',
        icone: 'fa-star',
        titre: 'Félicitations, votre candidature est retenue !',
        corps: `Votre place en filière ${f} est confirmée. Vous recevrez prochainement les informations relatives à la rentrée.`,
      }

    case 'liste_attente':
      return {
        variant: 'warning',
        icone: 'fa-hourglass-half',
        titre: "Vous êtes en liste d'attente",
        corps: `Filière ${f} — vous serez notifié(e) automatiquement en cas de désistement.`,
      }

    case 'indisponible':
      return {
        variant: 'info',
        icone: 'fa-circle-info',
        titre: 'Votre candidature a été clôturée.',
        corps:
          "Votre place n'a pas pu être maintenue. Pour toute question, contactez l'équipe projet via la page Aide.",
      }

    case 'non_retenu':
    default: {
      const m = (motif || '').trim()
      return {
        variant: 'info',
        icone: 'fa-circle-info',
        titre: "Votre candidature n'a pas été retenue.",
        corps: MESSAGE_NON_RETENU_GENERIQUE,
        motif: m || undefined,
      }
    }
  }
}
