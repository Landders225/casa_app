<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Évaluateur vue par l'ADMINISTRATEUR — `GET /api/admin/evaluateurs` (Lot 8d-1),
 * pour peupler le sélecteur d'affectation (`POST /admin/affectations`) et le
 * filtre `?evaluateur=` de la vue supervision.
 *
 * Liste BLANCHE stricte : identité d'équipe minimale (`prenom`/`nom`/`poste`),
 * jamais `email` ni aucune donnée liée au compte `utilisateur` — ce n'est ni un
 * annuaire ni une fiche de compte.
 *
 * @mixin \App\Models\MembreEquipe
 */
class EvaluateurResource extends JsonResource
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
