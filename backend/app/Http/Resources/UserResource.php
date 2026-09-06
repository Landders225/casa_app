<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Utilisateur courant — liste blanche STRICTE (ADR-02).
 *
 * Ne sérialise JAMAIS `mot_de_passe_hash` (🔴, seule colonne confidentielle de
 * `utilisateur`). On énumère les champs un par un — pas de `parent::toArray()`,
 * pas de `$this->resource->toArray()`.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profil = $this->profil();

        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role,
            'actif' => (bool) $this->actif,
            'derniere_connexion_le' => $this->derniere_connexion_le?->toIso8601String(),
            'profil' => match (true) {
                $this->isCandidat() && $profil !== null => new CandidatResource($profil),
                ! $this->isCandidat() && $profil !== null => new MembreEquipeResource($profil),
                default => null,
            },
        ];
    }
}
