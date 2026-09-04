<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Filière vue par le PUBLIC — `GET /api/filieres` (première route publique du
 * projet, Lot 6a). Liste blanche 🟢.
 *
 * `id` (Lot 8b-2) : l'UUID de filière est nécessaire au frontend candidat pour
 * `POST /api/candidatures {filiere_id}` et `PUT .../classement`. Ce n'est ni un
 * score, ni un rang, ni un quota — et il est DÉJÀ exposé au candidat authentifié
 * (`CandidatureCandidatResource.filiere.id`, `classement[].filiere.id`). Révision
 * assumée : l'identifiant de filière est public, ce n'est pas une fuite.
 * N'expose toujours JAMAIS : `quota` (attribut de `campagne_filiere`), `icone`,
 * ni rien d'autre du modèle interne. `actif` -> « Actuellement fermé ».
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
            'id' => $this->id,
            'code' => $this->code,
            'nom' => $this->nom,
            'description' => $this->description,
            'actif' => (bool) $this->actif,
        ];
    }
}
