<?php

namespace App\Domain\Scoring;

/**
 * Une sous-note d'entretien évaluée. `points` est déjà borné à `[0, max]`
 * (comme `Math.max(0, Math.min(note, sc.max))` de scoring.js). Objet immuable.
 *
 * NB : un sous-critère non renseigné vaut **0** (et non « non évalué ») —
 * fidèle à `scoring.js` (`rNotes[sc.code] ?? 0`).
 */
final class SousNoteScore
{
    public function __construct(
        public readonly string $code,
        public readonly string $sousCritereId,
        public readonly string $label,
        public readonly string $rubriqueCode,
        public readonly float $max,
        public readonly float $points,
    ) {}
}
