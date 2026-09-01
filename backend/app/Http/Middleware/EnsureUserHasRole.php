<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contrôle de rôle réutilisable (ADR-10).
 *
 * Usage : ->middleware('role:administrateur')
 *         ->middleware('role:evaluateur,administrateur')
 *
 * Doit être placé APRÈS `auth:sanctum` : renvoie 401 si non authentifié,
 * 403 si le rôle ne correspond pas.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        if (! $user->hasRole(...$roles)) {
            return response()->json(['message' => 'Accès refusé pour ce rôle.'], 403);
        }

        return $next($request);
    }
}
