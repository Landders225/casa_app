<?php

namespace App\Http\Resources\Admin;

use App\Models\MembreEquipe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Évaluateur vue par l'ADMINISTRATEUR — `GET /api/admin/evaluateurs` (Lot 8d-1),
 * pour peupler le sélecteur d'affectation (`POST /admin/affectations`), de
 * réattribution (`POST /admin/reattributions`, Lot 20b) et le filtre
 * `?evaluateur=` de la vue supervision.
 *
 * Liste BLANCHE stricte : identité d'équipe minimale (`prenom`/`nom`/`poste`)
 * + `actif` (Lot 20b, nécessaire pour la mention « désactivé » du filtre
 * supervision quand `?tous=1`) — jamais `email` ni aucune autre donnée liée
 * au compte `utilisateur` : ce n'est ni un annuaire ni une fiche de compte.
 *
 * @mixin MembreEquipe
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
            'actif' => (bool) $this->utilisateur?->actif,
        ];
    }
}
