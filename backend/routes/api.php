<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes API — CASA
|--------------------------------------------------------------------------
| Préfixe /api (bootstrap/app.php). Sanctum SPA : les requêtes stateful
| passent par EnsureFrontendRequestsAreStateful (statefulApi()).
*/

// Sonde applicative publique (indépendante de /up).
Route::get('/health', fn () => response()->json(['status' => 'ok', 'app' => 'CASA']));

// Authentification.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // --- Démonstration du filtrage par rôle (Lot 2, pas de métier) ---
    Route::get('/ping-candidat', PingController::class)
        ->defaults('espace', 'candidat')
        ->middleware('role:candidat');

    // ADR-10 : l'évaluation est ouverte à evaluateur ET administrateur.
    Route::get('/ping-evaluateur', PingController::class)
        ->defaults('espace', 'evaluateur')
        ->middleware('role:evaluateur,administrateur');

    // ADR-10 : l'administration est strictement réservée à administrateur.
    Route::get('/ping-admin', PingController::class)
        ->defaults('espace', 'administrateur')
        ->middleware('role:administrateur');
});
