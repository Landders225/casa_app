<?php

namespace App\Http\Resources\Admin;

use App\Domain\Rapports\ServiceRapports;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rapport de pilotage (Lot 11c, ADR-30) — enveloppe le résultat DÉJÀ AGRÉGÉ de
 * {@see ServiceRapports::agreger()}.
 *
 * `ServiceRapports` EST la liste blanche : il ne construit QUE des comptes, des
 * distributions et des taux — jamais un champ nominatif, jamais une ligne
 * individuelle, jamais une cross-tabulation. Cette Resource ne fait que
 * transporter la structure.
 *
 * @property array<string, mixed> $resource
 */
class RapportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
