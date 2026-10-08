<?php

namespace App\Domain\Export;

use App\Domain\Rapports\ServiceRapports;

/**
 * Contrôle de divulgation statistique partagé par les feuilles "Villes" et
 * "Synthèse" de l'export d'analyse — réutilise le seuil k-anonymat déjà en
 * vigueur dans le projet ({@see ServiceRapports::SEUIL_MASQUAGE}).
 *
 * Deux garde-fous combinés (demandés explicitement, Lot export-analyse) :
 *  1. toute catégorie d'effectif < k est affichée "<k" (jamais le nombre exact) ;
 *  2. si EXACTEMENT une catégorie est masquée, une seconde (la plus petite
 *     des catégories restantes) est masquée aussi — sinon
 *     Total − (toutes les catégories visibles) retrouverait exactement la
 *     valeur masquée. Dès que ≥ 2 catégories sont masquées, une équation à
 *     ≥ 2 inconnues ne se résout plus : aucune suppression supplémentaire
 *     n'est nécessaire ;
 *  3. le total n'est affiché QUE si aucune catégorie n'est masquée (jamais
 *     de total exact à côté d'une cellule "<k").
 */
final class SuppressionSecondaire
{
    /**
     * @param  array<string, int>  $effectifs  libellé => effectif, déjà agrégé
     * @return array{effectifs: array<string, int|string>, total: ?int}
     */
    public static function appliquer(array $effectifs, int $seuil): array
    {
        $masques = array_filter($effectifs, fn (int $n) => $n < $seuil);

        if (count($masques) === 1) {
            $restants = array_diff_key($effectifs, $masques);
            if ($restants !== []) {
                $cleSecondaire = array_keys($restants, min($restants))[0];
                $masques[$cleSecondaire] = $restants[$cleSecondaire];
            }
        }

        $affiches = [];
        foreach ($effectifs as $libelle => $n) {
            $affiches[$libelle] = array_key_exists($libelle, $masques) ? '<'.$seuil : $n;
        }

        return [
            'effectifs' => $affiches,
            'total' => $masques === [] ? array_sum($effectifs) : null,
        ];
    }
}
