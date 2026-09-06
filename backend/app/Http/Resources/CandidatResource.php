<?php

namespace App\Http\Resources;

use App\Models\Candidat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Profil candidat — liste blanche (ADR-02). Toutes les colonnes de `candidat`
 * sont 🟢 ; renvoyées uniquement à l'utilisateur lui-même (via /api/me).
 *
 * @mixin Candidat
 */
class CandidatResource extends JsonResource
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
            'sexe' => $this->sexe,
            'date_naissance' => $this->date_naissance?->toDateString(),
            'cni' => $this->cni,
            'telephone' => $this->telephone,
            'ville_residence' => $this->ville_residence,
            'residence_ci' => (bool) $this->residence_ci,
        ];
    }
}
