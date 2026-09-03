<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Filière vue par le PUBLIC — `GET /api/filieres` (première route publique du
 * projet, Lot 6a). LISTE BLANCHE STRICTE : uniquement les 4 champs 🟢.
 *
 * N'expose JAMAIS : `id`, `quota` (attribut de `campagne_filiere`), rien du
 * modèle interne. `actif` permet au front d'afficher « Actuellement fermé ».
 *
 * @mixin \App\Models\Filiere
 */
class FiliereResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'nom' => $this->nom,
            'description' => $this->description,
            'actif' => (bool) $this->actif,
        ];
    }
}
