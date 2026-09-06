<?php

namespace App\Http\Resources\Evaluateur;

use App\Models\PieceJustificative;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Métadonnées d'une pièce, vues par l'évaluateur. `url` pointe la route de
 * téléchargement ÉVALUATEUR (pas la route candidat). `chemin_stockage` n'est
 * jamais exposé (🔴).
 *
 * @mixin PieceJustificative
 */
class PieceEvaluateurResource extends JsonResource
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
            'nom_original' => $this->nom_original,
            'type_mime' => $this->type_mime,
            'taille_octets' => $this->taille_octets,
            'depose_le' => $this->depose_le?->toIso8601String(),
            'url' => "/api/evaluateur/pieces/{$this->id}/download",
        ];
    }
}
