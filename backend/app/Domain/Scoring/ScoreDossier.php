<?php

namespace App\Domain\Scoring;

/**
 * Résultat du calcul du volet Dossier (/65) — miroir de
 * `scoring.js` `computeScores()`.
 *
 *  - `total`     : `round( min(Σ score rubriques, 65) × 10 ) / 10` (1 décimale) ;
 *  - `rubriques` : les 6 RubriqueScore, indexées par `code`.
 *
 * Objet immuable, jamais sérialisé tel quel vers un candidat.
 */
final class ScoreDossier
{
    /**
     * @param  array<string, RubriqueScore>  $rubriques
     */
    public function __construct(
        public readonly float $total,
        public readonly float $voletMax,
        public readonly array $rubriques,
    ) {
    }

    public function rubrique(string $code): RubriqueScore
    {
        return $this->rubriques[$code];
    }
}
