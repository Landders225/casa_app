<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FiliereResource;
use App\Models\Filiere;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Filières — `GET /api/filieres` (Lot 6a). PREMIÈRE ROUTE PUBLIQUE du projet
 * (hors `auth:sanctum`) : le catalogue des CQP pour le site vitrine.
 *
 * `FiliereResource` = liste blanche stricte des 4 champs 🟢. Une filière
 * `actif = false` reste listée (le front affiche « Actuellement fermé »).
 */
class FiliereController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return FiliereResource::collection(
            Filiere::query()->orderBy('nom')->get(),
        );
    }
}
