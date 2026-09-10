<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-vérifie `utilisateur.actif` à CHAQUE requête authentifiée (Lot 11b, ADR-29).
 *
 * `AuthController::login` refuse déjà un compte désactivé — mais seulement AU
 * MOMENT du login. Sans ce middleware, une session déjà ouverte survivait
 * jusqu'à son expiration (`SESSION_LIFETIME`, 120 min) : un membre du jury
 * écarté EN URGENCE aurait gardé l'accès aux dossiers candidats (données
 * personnelles) pendant 2 h. « Désactiver » doit couper l'accès MAINTENANT.
 *
 * Placé APRÈS `auth:sanctum` dans le groupe stateful : si `actif = false`, la
 * session web est invalidée (l'accès ne « revient » pas tout seul — une
 * réactivation impose une nouvelle connexion, cohérent avec le login) et la
 * requête est refusée en 401 — le SPA rebascule alors sur l'écran de connexion,
 * où un nouveau login est à son tour refusé (message générique).
 *
 * No-op pour une requête non authentifiée (aucun `user()`), pour laquelle
 * `auth:sanctum` a déjà tranché.
 */
class EnsureUserActif
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->actif) {
            Log::warning('Requête sur une session dont le compte est désactivé', [
                'utilisateur_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
            ]);

            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json(['message' => 'Votre compte a été désactivé.'], 401);
        }

        return $next($request);
    }
}
