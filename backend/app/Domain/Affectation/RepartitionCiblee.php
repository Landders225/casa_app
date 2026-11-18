<?php

namespace App\Domain\Affectation;

/**
 * Répartition DÉTERMINISTE de dossiers selon des cibles EXACTES par
 * évaluateur (Lot 3, ADR-48) — fonction PURE (aucune base, aucune horloge),
 * testée seule. Miroir de `RepartitionEquilibree` pour le cas où des cibles
 * ont été résolues (`ResolutionCibles`).
 *
 * Remplit chaque évaluateur, DANS L'ORDRE REÇU, jusqu'à sa cible exacte, en
 * consommant les dossiers dans l'ordre reçu. Si la somme des cibles est
 * INFÉRIEURE au nombre de dossiers, les dossiers excédentaires ne sont PAS
 * affectés (absents du résultat) — ce n'est pas une erreur, c'est le reliquat
 * documenté par `ResolutionCibles`. Mêmes entrées → même résultat.
 */
final class RepartitionCiblee
{
    /**
     * @param  array<string, int>  $cibles  id évaluateur => nombre exact à recevoir, DANS L'ORDRE de départage
     * @param  list<string>  $dossiers  ids de dossiers, dans l'ordre d'attribution
     * @return array<string, string> id dossier => id évaluateur (sous-ensemble de $dossiers si somme(cibles) < count($dossiers))
     */
    public static function repartir(array $cibles, array $dossiers): array
    {
        $attribution = [];
        $i = 0;
        $total = count($dossiers);

        foreach ($cibles as $evaluateurId => $cible) {
            for ($k = 0; $k < $cible && $i < $total; $k++, $i++) {
                $attribution[$dossiers[$i]] = $evaluateurId;
            }
        }

        return $attribution;
    }
}
