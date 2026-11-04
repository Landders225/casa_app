<?php

namespace App\Domain\Affectation;

/**
 * Répartition DÉTERMINISTE et ÉQUILIBRÉE de dossiers entre évaluateurs
 * (ADR-45) — fonction PURE (aucune base, aucune horloge), testée seule.
 *
 * Règle « toujours le moins chargé » : chaque dossier, dans l'ordre reçu, va à
 * l'évaluateur dont la charge courante (charge existante + dossiers déjà
 * attribués par cette répartition) est la plus faible ; à égalité, le premier
 * dans l'ordre reçu (l'appelant fixe cet ordre : nom, prénom, id). Effet :
 * les écarts de charge existants sont d'abord comblés, puis la répartition
 * est égale à une unité près. Mêmes entrées → même résultat.
 *
 * O(dossiers × évaluateurs) : négligeable au plafond de 1 000 dossiers et
 * pour une équipe d'évaluateurs de taille réduite.
 */
final class RepartitionEquilibree
{
    /**
     * @param  array<string, int>  $chargesExistantes  id évaluateur => charge, DANS L'ORDRE de départage
     * @param  list<string>  $dossiers  ids de dossiers, dans l'ordre d'attribution
     * @return array<string, string> id dossier => id évaluateur
     */
    public static function repartir(array $chargesExistantes, array $dossiers): array
    {
        if ($chargesExistantes === []) {
            return [];
        }

        $charges = $chargesExistantes;
        $attribution = [];

        foreach ($dossiers as $dossier) {
            $cible = null;
            foreach ($charges as $evaluateur => $charge) {
                if ($cible === null || $charge < $charges[$cible]) {
                    $cible = $evaluateur;
                }
            }
            $attribution[$dossier] = (string) $cible;
            $charges[$cible]++;
        }

        return $attribution;
    }
}
