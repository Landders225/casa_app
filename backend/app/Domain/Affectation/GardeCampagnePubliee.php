<?php

namespace App\Domain\Affectation;

use App\Models\Publication;
use Illuminate\Support\Collection;

/**
 * Une campagne dont les résultats sont PUBLIÉS (ADR-08) ne doit plus voir ses
 * dossiers bouger d'évaluateur — affectation manuelle, réattribution ou
 * affectation automatique (ADR-45). SOURCE UNIQUE de la requête
 * `Publication::whereIn('campagne_id', …)->exists()`, auparavant dupliquée
 * entre `ServiceAffectationAutomatique` (seul endroit qui l'avait) et les
 * deux endroits qui en avaient besoin sans l'avoir (`AffectationController`,
 * `ReattributionController`).
 *
 * Volontairement une simple fonction booléenne, PAS une exception : chaque
 * appelant garde sa propre forme de refus (`ValidationException` 422 pour
 * l'affectation manuelle/réattribution — cohérent avec leurs autres refus
 * atomiques ; `AffectationAutomatiqueRefusee` 409 `campagne_publiee` pour
 * l'affectation automatique, inchangé).
 */
final class GardeCampagnePubliee
{
    /**
     * @param  Collection<int, string>  $campagneIds
     */
    public static function estPubliee(Collection $campagneIds): bool
    {
        return $campagneIds->isNotEmpty()
            && Publication::query()->whereIn('campagne_id', $campagneIds)->exists();
    }
}
