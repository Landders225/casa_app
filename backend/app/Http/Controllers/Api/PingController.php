<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Routes de démonstration du filtrage par rôle (Lot 2). Aucune logique métier :
 * elles prouvent seulement que le middleware `role:` et le guard `auth:sanctum`
 * fonctionnent. À remplacer par les vrais endpoints aux lots suivants.
 */
class PingController extends Controller
{
    public function __invoke(Request $request, string $espace): JsonResponse
    {
        return response()->json([
            'espace' => $espace,
            'utilisateur' => $request->user()->email,
            'role' => $request->user()->role,
        ]);
    }
}
