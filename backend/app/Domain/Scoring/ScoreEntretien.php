<?php

namespace App\Domain\Scoring;

/**
 * Résultat du calcul du volet Entretien (/35) — miroir de
 * `scoring.js` `computeEntretienScore()`.
 *
 *  - `total`     : `round( min(Σ score rubriques, 35) × 10 ) / 10` ;
 *  - `rubriques` : les 4 RubriqueScore, indexées par `code` ;
 *  - `sousNotes` : les 10 SousNoteScore (bornées, jamais « nulles »).
 *
 * Aucun rééchelonnage (contrairement au dossier) : les sous-notes sont
 * directement en points. Objet immuable, jamais sérialisé vers un candidat.
 */
final class ScoreEntretien
{
    /**
     * @param  array<string, RubriqueScore>  $rubriques
     * @param  list<SousNoteScore>  $sousNotes
     */
    public function __construct(
        public readonly float $total,
        public readonly float $voletMax,
        public readonly array $rubriques,
        public readonly array $sousNotes,
    ) {
    }

    public function rubrique(string $code): RubriqueScore
    {
        return $this->rubriques[$code];
    }
}
