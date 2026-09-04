<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\EvaluateurResource;
use App\Models\MembreEquipe;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * ÉVALUATEURS — liste de lecture (Lot 8d-1) — administrateur strict.
 *
 *   GET /api/admin/evaluateurs
 *
 * Alimente le sélecteur de `POST /admin/affectations` et le filtre
 * `?evaluateur=` de `GET /admin/candidatures` (Lot 6a) — aucun des deux
 * n'avait de point de lecture pour peupler un choix côté client.
 *
 * Non paginé (équipe de taille réduite, cf. `GET /api/filieres`). Liste
 * BLANCHE stricte via `EvaluateurResource` : jamais l'e-mail ni une donnée de
 * compte `utilisateur`.
 */
class EvaluateurController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $evaluateurs = MembreEquipe::query()
            ->whereHas('utilisateur', fn ($q) => $q->where('role', 'evaluateur'))
            ->orderBy('prenom')
            ->get();

        return EvaluateurResource::collection($evaluateurs);
    }
}
