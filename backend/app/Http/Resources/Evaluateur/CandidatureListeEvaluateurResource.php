<?php

namespace App\Http\Resources\Evaluateur;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ligne de la liste « mes dossiers » de l'évaluateur (vue allégée).
 *
 * @mixin \App\Models\Candidature
 */
class CandidatureListeEvaluateurResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero_dossier' => $this->numero_dossier,
            'statut_interne' => $this->statut_interne,
            'statut_eligibilite_interne' => $this->statut_eligibilite_interne,
            'date_soumission' => $this->date_soumission?->toIso8601String(),
            'candidat' => $this->whenLoaded('candidat', fn () => $this->candidat ? [
                'prenom' => $this->candidat->prenom,
                'nom' => $this->candidat->nom,
                'ville_residence' => $this->candidat->ville_residence,
            ] : null),
            'filiere' => $this->whenLoaded('filiere', fn () => [
                'code' => $this->filiere->code,
                'nom' => $this->filiere->nom,
            ]),
            'verification_faite' => $this->verification !== null,
        ];
    }
}
