<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Métadonnées d'une pièce justificative — LISTE BLANCHE (ADR-02).
 *
 * N'expose JAMAIS `chemin_stockage` (🔴, chemin interne sur le disque privé).
 * `url` est une route applicative authentifiée (streaming), pas un lien statique.
 *
 * @mixin \App\Models\PieceJustificative
 */
class PieceJustificativeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rattachement' => $this->rattachement,
            'type_document_code' => $this->type_document_code,
            'experience_id' => $this->whenLoaded('experience', fn () => $this->experience?->id),
            'nom_original' => $this->nom_original,
            'type_mime' => $this->type_mime,
            'taille_octets' => $this->taille_octets,
            'depose_le' => $this->depose_le?->toIso8601String(),
            'url' => "/api/pieces/{$this->id}/download",
        ];
    }
}
