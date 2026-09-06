<?php

namespace App\Domain\Eligibilite;

use App\Models\Candidature;
use Carbon\CarbonInterface;

/**
 * Logique d'éligibilité — codée et versionnée par le déploiement de code
 * (ADR-06), PAS un moteur de règles générique. Portage FIDÈLE de
 * App_maquette/assets/js/scoring.js :
 *  - evaluerSoumission()          <-> checkCriteresEliminatoires (§4.9)
 *  - evaluerVerificationEvaluateur() <-> checkCriteresEliminatoiresEvaluateur
 *
 * Chaque critère est renvoyé individuellement (tracé dans
 * `critere_eliminatoire_declenche`). eligible == (aucun critère renvoyé).
 *
 * NB (D-3c-1) : `residence_ci` n'est PAS testé ici — scoring.js ne le contrôle
 * que dans checkEligibiliteInitiale (§4.2, inscription). Point ouvert : ADR-13.
 */
final class ServiceEligibilite
{
    /** Version de l'algorithme (change avec le code, jamais en base). */
    public const VERSION = 1;

    /** Bornes d'âge (scoring.js). */
    private const AGE_MIN = 18;

    private const AGE_MAX = 30;

    /** Seuil de français : moyenne écrit/parlé/compréhension >= 2 (échelle 0-3). */
    private const SEUIL_FRANCAIS = 2.0;

    /**
     * Critères éliminatoires connus AU MOMENT DE LA SOUMISSION.
     * L'âge est calculé à la date d'ouverture de la campagne (référence fixe
     * pour toute la cohorte).
     *
     * @return list<CritereDeclenche>
     */
    public function evaluerSoumission(Candidature $candidature): array
    {
        $r = $candidature->reponseFormulaire;
        $criteres = [];

        $age = $this->age(
            $candidature->candidat->date_naissance,
            $candidature->campagne->date_ouverture,
        );
        if ($age < self::AGE_MIN) {
            $criteres[] = new CritereDeclenche('age_min', 'Âge inférieur à 18 ans');
        }
        if ($age > self::AGE_MAX) {
            $criteres[] = new CritereDeclenche('age_max', 'Âge supérieur à 30 ans');
        }

        if ($r->sc01_scolarise_actuellement === 'oui') {
            $criteres[] = new CritereDeclenche('SC.01', 'Candidat actuellement scolarisé');
        }
        if ($r->sc02_derniere_classe === 'avant_3e') {
            $criteres[] = new CritereDeclenche('SC.02', 'Dernière classe fréquentée antérieure à la 3ème');
        }
        if ($r->sc05_beneficiaire_formation_actuelle === 'oui') {
            $criteres[] = new CritereDeclenche('SC.05', "Bénéficiaire actuel d'un programme de formation");
        }
        if (in_array($r->se03_situation_emploi, ['temps_partiel', 'temps_plein'], true)) {
            $criteres[] = new CritereDeclenche('SE.03', 'En emploi à temps partiel ou plein temps');
        }

        $moyenneFrancais = $this->moyenne([
            $r->langue_ecrit, $r->langue_parle, $r->langue_comprehension,
        ]);
        if ($moyenneFrancais < self::SEUIL_FRANCAIS) {
            $criteres[] = new CritereDeclenche(
                'francais',
                sprintf('Niveau moyen de français %.1f/3, inférieur au seuil 2', $moyenneFrancais),
            );
        }

        // Règle 3 : logique OR — seul le cumul des deux "non" est bloquant.
        if ($r->acces_plateau === 'non' && $r->acces_deux_plateaux_vallons === 'non') {
            $criteres[] = new CritereDeclenche(
                'acces_sites',
                'Ne peut suivre les formations ni au Plateau ni aux 2 Plateaux Vallons',
            );
        }

        if ($r->di01_disponible_lun_ven === 'non') {
            $criteres[] = new CritereDeclenche('DI.01', 'Non disponible du lundi au vendredi');
        }
        if ($r->di03_engagement_complet === 'non') {
            $criteres[] = new CritereDeclenche('DI.03', "N'a pas confirmé son engagement");
        }

        return $criteres;
    }

    /**
     * Critères connus SEULEMENT après vérification évaluateur (diplôme confirmé
     * par pièce, nationalité confirmée par la CNI). NON appelé au Lot 3c —
     * `verification_dossier` n'existe pas encore. Fourni pour le lot évaluation.
     *
     * @param  array{diplome_verifie?: ?string, nationalite_confirmee?: ?bool}  $verification
     * @return list<CritereDeclenche>
     */
    public function evaluerVerificationEvaluateur(array $verification): array
    {
        $criteres = [];

        if (($verification['diplome_verifie'] ?? null) === 'cepe') {
            $criteres[] = new CritereDeclenche('SC.04', 'Plus haut diplôme confirmé = CEPE', 'verification_evaluateur');
        }
        if (array_key_exists('nationalite_confirmee', $verification)
            && $verification['nationalite_confirmee'] === false) {
            $criteres[] = new CritereDeclenche('nationalite', 'Nationalité ivoirienne non confirmée par la CNI', 'verification_evaluateur');
        }

        return $criteres;
    }

    private function age(CarbonInterface $naissance, CarbonInterface $reference): int
    {
        return (int) $naissance->copy()->startOfDay()->diffInYears($reference->copy()->startOfDay());
    }

    /**
     * Moyenne façon scoring.js `niveauMoyenne` : un niveau absent compte 0.
     *
     * @param  array<int, int|null>  $niveaux
     */
    private function moyenne(array $niveaux): float
    {
        $somme = array_sum(array_map(static fn ($n) => $n ?? 0, $niveaux));

        return $somme / count($niveaux);
    }
}
