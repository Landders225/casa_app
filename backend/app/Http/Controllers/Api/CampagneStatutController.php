<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campagne;
use Illuminate\Http\JsonResponse;

/**
 * Signal minimal pour l'espace candidat : une campagne est-elle ouverte ?
 * (ADR-42). Authentifié, AUCUNE restriction de rôle — cette information
 * n'est sensible pour personne, et sert UNIQUEMENT à éviter d'afficher un
 * bouton « Commencer ma candidature » qui échouerait à coup sûr.
 * `POST /api/candidatures` (Lot 6a) reste et restera la seule vraie
 * barrière — ce contrôleur n'en est qu'un reflet, jamais une garde.
 *
 * Liste blanche d'UN SEUL booléen : jamais l'id, le nom ni les dates d'une
 * campagne (réservés à l'admin, `CampagneResource`, ADR-23).
 */
class CampagneStatutController extends Controller
{
    public function ouverte(): JsonResponse
    {
        return response()->json([
            'data' => [
                'campagne_ouverte' => Campagne::ouverte()->exists(),
            ],
        ]);
    }
}
