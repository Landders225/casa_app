<?php

namespace App\Http\Resources;

use App\Models\ExperienceProfessionnelle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Expérience professionnelle déclarée (liste blanche). `piece_justificative_id`
 * brut n'est pas exposé ; `justificatif` porte les métadonnées de la pièce
 * (Lot 3b) ou null.
 *
 * @mixin ExperienceProfessionnelle
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
            'justificatif' => $this->whenLoaded(
                'pieceJustificative',
                fn () => $this->pieceJustificative
                    ? new PieceJustificativeResource($this->pieceJustificative)
                    : null,
            ),
        ];
    }
}
