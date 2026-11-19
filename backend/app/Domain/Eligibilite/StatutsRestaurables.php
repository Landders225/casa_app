<?php

namespace App\Domain\Eligibilite;

/**
 * Lot 4 (ADR-49) — `statut_interne` pour lesquels un rétablissement
 * d'éligibilité est permis. LISTE BLANCHE volontaire (pas une liste noire
 * excluant seulement `brouillon`) : un `statut_interne` futur, ajouté au CHECK
 * de `candidature` sans mise à jour explicite de cette liste, est refusé par
 * défaut plutôt qu'autorisé par défaut.
 *
 * Les 4 valeurs ci-dessous sont les SEULES qui peuvent aujourd'hui coexister
 * avec `statut_eligibilite_interne = 'non_eligible'` (un `brouillon` n'a
 * jamais traversé `ServiceEligibilite`, donc n'est jamais `non_eligible`).
 */
final class StatutsRestaurables
{
    public const AUTORISES = ['soumis', 'en_instruction', 'non_eligible', 'evalue'];

    public static function autorise(string $statutInterne): bool
    {
        return in_array($statutInterne, self::AUTORISES, true);
    }
}
