<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Profil membre d'équipe — liste blanche (ADR-02).
 *
 * @mixin \App\Models\MembreEquipe
 */
class MembreEquipeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prenom' => $this->prenom,
            'nom' => $this->nom,
            'poste' => $this->poste,
        ];
    }
}
