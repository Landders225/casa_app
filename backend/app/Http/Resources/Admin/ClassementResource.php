<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Classement complet d'une campagne, vu par l'ADMINISTRATEUR (Lot 5a).
 *
 * ⚠️ STRICT — `rang` et `motif_interne` sont 🔴 ; `decision` et
 * `motif_communicable` sont 🟡 mais NON exposés au candidat tant qu'aucune
 * `publication` n'existe. Cette Resource est **administrateur-only** et n'est
 * JAMAIS réutilisée côté candidat ni évaluateur (`NonFuiteClassementTest` le
 * prouve sur le vrai chemin, D-3b-7).
 *
 * Enveloppe un tableau assemblé par `ClassementController` (décisions persistées
 * + scores/départage recalculés à la volée depuis les snapshots figés).
 *
 * @property array<string, mixed> $resource
 */
class ClassementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
