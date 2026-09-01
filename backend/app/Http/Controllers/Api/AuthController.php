<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Authentification Sanctum SPA (ADR-01) — session-cookie same-origin,
 * jamais de token Bearer.
 */
class AuthController extends Controller
{
    /**
     * POST /api/login — ouvre la session.
     *
     * Échec 100 % générique : un mot de passe correct sur un compte désactivé
     * renvoie EXACTEMENT le même message qu'un mot de passe faux ou un e-mail
     * inconnu (pas de confirmation « ce couple est valide, le compte est juste
     * désactivé »). L'événement est journalisé côté serveur pour le support.
     */
    public function login(LoginRequest $request): UserResource
    {
        $credentials = $request->validated();

        $user = User::query()->where('email', $credentials['email'])->first();
        $passwordOk = $user !== null && Hash::check($credentials['password'], $user->getAuthPassword());

        if (! $passwordOk || ! $user->actif) {
            if ($passwordOk && $user !== null && ! $user->actif) {
                Log::warning('Tentative de connexion sur un compte désactivé', [
                    'utilisateur_id' => $user->id,
                    'email' => $user->email,
                    'ip' => $request->ip(),
                ]);
            }

            throw ValidationException::withMessages([
                'email' => ['E-mail ou mot de passe incorrect.'],
            ]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $user->forceFill(['derniere_connexion_le' => now()])->saveQuietly();

        return new UserResource($user->load('candidat', 'membreEquipe'));
    }

    /**
     * GET /api/me — utilisateur authentifié courant + profil associé.
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('candidat', 'membreEquipe'));
    }

    /**
     * POST /api/logout — invalide la session.
     */
    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
