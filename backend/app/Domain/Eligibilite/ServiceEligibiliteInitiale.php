<?php

namespace App\Domain\Eligibilite;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Éligibilité INITIALE — vérifiée à l'inscription, AVANT le formulaire détaillé
 * (Lot 7). Portage FIDÈLE de App_maquette/assets/js/scoring.js
 * `checkEligibiliteInitiale` (§4.2), codé et versionné par le déploiement de code
 * (ADR-06), PAS un moteur de règles générique.
 *
 * Ne contrôle QUE ce qui est connu au moment de l'inscription :
 *  - âge dans [18, 30] (référence = maintenant, comme le wizard `inscription.html`) ;
 *  - résidence en Côte d'Ivoire.
 *
 * Volontairement EXCLUS de ce service (≠ scoring.js `checkEligibiliteInitiale`) :
 *  - nationalité : jamais auto-déclarée par le candidat (ADR-07) — vérifiée par
 *    l'évaluateur sur la CNI (verification_dossier) ;
 *  - `sc01` (scolarisé) / `se03` (emploi) : le formulaire détaillé n'existe pas
 *    encore à l'inscription ; ces critères sont repris à la SOUMISSION par
 *    App\Domain\Eligibilite\ServiceEligibilite::evaluerSoumission().
 *
 * DOUBLE GATE assumé : l'âge est re-contrôlé à la soumission
 * (`ServiceEligibilite`, référence = `campagne.date_ouverture`). Un candidat
 * inscrit à 30 ans peut basculer hors tranche si la campagne ouvre plus tard —
 * c'est le comportement voulu (fidèle aux deux fonctions de scoring.js).
 *
 * Ferme la dette D-3c-1 / ADR-13 : `residence_ci` n'était contrôlé nulle part
 * (scoring.js ne le teste qu'ici, jamais dans `checkCriteresEliminatoires`).
 */
final class ServiceEligibiliteInitiale
{
    /** Version de l'algorithme (change avec le code, jamais en base). */
    public const VERSION = 1;

    /** Bornes d'âge (scoring.js). */
    private const AGE_MIN = 18;
    private const AGE_MAX = 30;

    /**
     * @return list<string>  motifs de refus ; liste vide == éligible à l'inscription
     */
    public function evaluer(
        CarbonInterface $dateNaissance,
        bool $residenceCi,
        ?CarbonInterface $reference = null,
    ): array {
        $reference ??= CarbonImmutable::now();
        $motifs = [];

        $age = (int) $dateNaissance->copy()->startOfDay()->diffInYears($reference->copy()->startOfDay());
        if ($age < self::AGE_MIN || $age > self::AGE_MAX) {
            $motifs[] = "Le programme CASA s'adresse aux personnes de 18 à 30 ans.";
        }

        if (! $residenceCi) {
            $motifs[] = "Le programme CASA est réservé aux personnes résidant en Côte d'Ivoire.";
        }

        return $motifs;
    }

    public function estEligible(CarbonInterface $dateNaissance, bool $residenceCi, ?CarbonInterface $reference = null): bool
    {
        return $this->evaluer($dateNaissance, $residenceCi, $reference) === [];
    }
}
