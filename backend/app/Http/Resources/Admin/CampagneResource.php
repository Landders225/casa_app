<?php

namespace App\Http\Resources\Admin;

use App\Models\Campagne;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Campagne vue par l'ADMINISTRATEUR — `GET /api/admin/campagnes` (Lot 8d-1),
 * réponse des écritures `POST`/`PUT` (Lot 17).
 *
 * Table `campagne` 🟢 (non confidentielle, cf. `Campagne::class`). Liste
 * BLANCHE limitée aux champs de GESTION (ouvrir/clôturer, éditer, suivi) : ni
 * `description`, ni timestamps.
 *
 * Depuis le Lot 17 : `filieres` (code/nom/quota — nécessaire au panneau
 * d'édition des quotas), et trois indicateurs qui gouvernent CE QUE l'écran
 * peut proposer (aucune règle de garde-fou devinée côté client, comme le
 * reste de l'admin) :
 *  - `classement_calcule` : au moins une `decision_candidature` existe ;
 *  - `classement_perime`  : ce classement ne reflète plus les quotas actuels
 *    (Étape 1, Q2 — un quota a été modifié après le calcul, sans recalcul
 *    explicite depuis) ;
 *  - `publiee` : une `publication` existe — au-delà, quotas ET dates sont
 *    verrouillés (nom seul reste éditable, cf. `CampagneController`).
 *
 * `filieres`/`classement_calcule`/`publiee` dépendent d'un eager-load
 * (`with('filieres')`, `withExists(['publication', 'decisionsCandidature'])`)
 * fait par le contrôleur — jamais requêtés ici (pas de N+1, Étape 1 Q4).
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
            'classement_calcule' => (bool) ($this->decisions_candidature_exists ?? false),
            'classement_perime' => (bool) $this->classement_perime,
            'publiee' => (bool) ($this->publication_exists ?? false),
            'filieres' => $this->whenLoaded('filieres', fn () => $this->filieres->map(fn ($f) => [
                'id' => $f->id,
                'code' => $f->code,
                'nom' => $f->nom,
                'quota' => (int) $f->pivot->quota,
            ])->values()),
        ];
    }
}
