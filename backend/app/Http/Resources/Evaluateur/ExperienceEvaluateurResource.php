<?php

namespace App\Http\Resources\Evaluateur;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Expérience professionnelle déclarée, vue par l'évaluateur (avec le
 * justificatif rattaché).
 *
 * @mixin \App\Models\ExperienceProfessionnelle
 */
class ExperienceEvaluateurResource extends JsonResource
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
                    ? new PieceEvaluateurResource($this->pieceJustificative)
                    : null,
            ),
        ];
    }
}
