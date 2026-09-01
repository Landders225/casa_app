<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une ligne de classement de préférence (filière + rang). Toutes données 🟢
 * (préférence exprimée par le candidat lui-même).
 *
 * @mixin \App\Models\ClassementFilierePreference
 */
class ClassementPreferenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'rang' => $this->rang,
            'filiere' => $this->whenLoaded('filiere', fn () => [
                'id' => $this->filiere->id,
                'code' => $this->filiere->code,
                'nom' => $this->filiere->nom,
            ]),
        ];
    }
}
