<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Candidat\CandidatureController;
use App\Http\Controllers\Api\Candidat\ClassementController;
use App\Http\Controllers\Api\Candidat\ExperienceController;
use App\Http\Controllers\Api\Candidat\ReponseFormulaireController;
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

    /*
    |----------------------------------------------------------------------
    | Lot 3a — Candidature en brouillon (rôle candidat, policy propriétaire)
    |----------------------------------------------------------------------
    | La candidature d'un autre candidat renvoie 404 (CandidaturePolicy ->
    | denyAsNotFound), jamais 403.
    */
    Route::middleware('role:candidat')->group(function () {
        Route::get('/candidature', [CandidatureController::class, 'courante']);
        Route::post('/candidatures', [CandidatureController::class, 'store']);

        Route::prefix('candidatures/{candidature}')->scopeBindings()->group(function () {
            Route::get('/', [CandidatureController::class, 'show']);
            Route::patch('/reponses', [ReponseFormulaireController::class, 'update']);
            Route::post('/confirmer-filiere', [CandidatureController::class, 'confirmerFiliere']);

            Route::post('/experiences', [ExperienceController::class, 'store']);
            Route::patch('/experiences/{experience}', [ExperienceController::class, 'update']);
            Route::delete('/experiences/{experience}', [ExperienceController::class, 'destroy']);

            Route::put('/classement', [ClassementController::class, 'update']);
        });
    });
});
