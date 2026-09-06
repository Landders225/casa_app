<?php

namespace App\Http\Resources\Admin;

use App\Models\Campagne;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Campagne vue par l'ADMINISTRATEUR — `GET /api/admin/campagnes` (Lot 8d-1).
 *
 * Table `campagne` 🟢 (non confidentielle, cf. `Campagne::class`). Liste
 * BLANCHE volontairement limitée aux champs de GESTION (ouvrir/clôturer,
 * suivi) : ni `description`, ni timestamps, ni relation `filieres`/`publication`.
 *
 * @mixin Campagne
 */
class CampagneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'statut' => $this->statut,
            'date_ouverture' => $this->date_ouverture?->toDateString(),
            'date_cloture' => $this->date_cloture?->toDateString(),
            'places_totales' => $this->places_totales,
        ];
    }
}
