<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Confirmation de la publication des résultats d'une campagne (Lot 5b) —
 * administrateur-only. Aucune donnée candidat ; c'est un accusé d'acte.
 *
 * @property array<string, mixed> $resource
 */
class PublicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
