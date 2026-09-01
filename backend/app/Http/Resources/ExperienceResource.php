<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Expérience professionnelle déclarée (liste blanche). `piece_justificative_id`
 * n'est pas exposé : gestion du justificatif au Lot 3b.
 *
 * @mixin \App\Models\ExperienceProfessionnelle
 */
class ExperienceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domaine' => $this->domaine,
            'duree_categorie' => $this->duree_categorie,
        ];
    }
}
