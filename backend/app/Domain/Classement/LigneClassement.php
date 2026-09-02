<?php

namespace App\Domain\Classement;

/**
 * Une ligne du classement d'une filière — résultat du calcul de
 * `ServiceClassement`. Objet immuable, jamais sérialisé tel quel vers un candidat.
 *
 *  - `rang`        : position 1-indexée dans la filière ; NULL si `nonEligible`
 *    (les non-éligibles ne concourent pas — D-5a-4) ;
 *  - `decision`    : retenu / liste_attente / non_retenu ;
 *  - `departage`   : {mixite_f: bool, vulnerabilite: int, experience_secteur: bool, mo04: int}
 *    — utilisé pour le tri à égalité de score, exposé pour la transparence staff.
 */
final class LigneClassement
{
    /**
     * @param  array{mixite_f: bool, vulnerabilite: int, experience_secteur: bool, mo04: int}  $departage
     */
    public function __construct(
        public readonly string $candidatureId,
        public readonly string $filiereCode,
        public readonly ?int $rang,
        public readonly string $decision,
        public readonly float $scoreFinal,
        public readonly float $scoreDossier,
        public readonly float $scoreEntretien,
        public readonly array $departage,
        public readonly bool $nonEligible,
    ) {
    }
}
