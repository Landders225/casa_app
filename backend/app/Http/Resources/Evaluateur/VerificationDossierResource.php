<?php

namespace App\Http\Resources\Evaluateur;

use App\Models\VerificationDossier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vérification du dossier (🔴 — rôle évaluateur/admin uniquement, jamais candidat).
 *
 * @mixin VerificationDossier
 */
class VerificationDossierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'nationalite_confirmee' => $this->nationalite_confirmee,
            'diplome_verifie' => $this->diplome_verifie,
            'verifie_le' => $this->verifie_le?->toIso8601String(),
            'verifie_par' => $this->whenLoaded('verifiePar', fn () => $this->verifiePar ? [
                'id' => $this->verifiePar->id,
                'prenom' => $this->verifiePar->prenom,
                'nom' => $this->verifiePar->nom,
            ] : null),
        ];
    }
}
