<?php

namespace App\Domain\Scoring;

/**
 * Score d'une rubrique du volet Dossier. `score` est déjà plafonné à `max`
 * (comme `Math.min(raw, poids)` de scoring.js) mais N'EST PAS arrondi : c'est
 * la somme des `score` non arrondis qui donne le total (puis arrondi une seule
 * fois à une décimale). Objet immuable.
 */
final class RubriqueScore
{
    public function __construct(
        public readonly string $code,
        public readonly string $rubriqueId,
        public readonly string $label,
        public readonly float $max,
        public readonly float $score,
    ) {}
}
