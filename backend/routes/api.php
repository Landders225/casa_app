<?php

use App\Domain\Piece\ContraintesFichier;
use App\Http\Controllers\Api\Admin\AffectationController;
use App\Http\Controllers\Api\Admin\AuditController;
use App\Http\Controllers\Api\Admin\CampagneController as AdminCampagneController;
use App\Http\Controllers\Api\Admin\CandidatureSupervisionController;
use App\Http\Controllers\Api\Admin\ClassementController as AdminClassementController;
use App\Http\Controllers\Api\Admin\CorrectionController;
use App\Http\Controllers\Api\Admin\EliminationController;
use App\Http\Controllers\Api\Admin\FiliereController as AdminFiliereController;
use App\Http\Controllers\Api\Admin\PublicationController;
use App\Http\Controllers\Api\Admin\RemplacementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Candidat\CandidatureController;
use App\Http\Controllers\Api\Candidat\ProfilController;
use App\Http\Controllers\Api\RegisterController;
use App\Http\Controllers\Api\FiliereController;
use App\Http\Controllers\Api\Candidat\ClassementController;
use App\Http\Controllers\Api\Candidat\ExperienceController;
use App\Http\Controllers\Api\Candidat\JustificatifExperienceController;
use App\Http\Controllers\Api\Candidat\PieceController;
use App\Http\Controllers\Api\Candidat\PieceDossierController;
use App\Http\Controllers\Api\Candidat\ReponseFormulaireController;
use App\Http\Controllers\Api\Candidat\SoumissionController;
use App\Http\Controllers\Api\Evaluateur\DossierController;
use App\Http\Controllers\Api\Evaluateur\EntretienController;
use App\Http\Controllers\Api\Evaluateur\EvaluationController;
use App\Http\Controllers\Api\Evaluateur\PieceEvaluateurController;
use App\Http\Controllers\Api\Evaluateur\VerificationController;
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

// Catalogue des filières — PUBLIC (Lot 6a). Liste blanche stricte : code, nom,
// description, actif (le front affiche « Actuellement fermé » si actif=false).
Route::get('/filieres', [FiliereController::class, 'index']);

// Authentification.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Inscription candidat — PUBLIC (Lot 7, comble ADR-13). Crée le compte seul
// (utilisateur role=candidat + candidat) ; la candidature reste POST /api/candidatures.
Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:register');

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
    | Lot 5a — Classement & décisions internes (administrateur strict)
    |----------------------------------------------------------------------
    | Calcul du score final /100, tri par filière (départage scoring.js +
    | D-5a-1), attribution retenu / liste d'attente / non retenu. Persiste
    | `decision_candidature` SANS publication -> le candidat ne voit toujours
    | rien (StatutPublicResolver inchangé). Recalculable tant que non publié.
    */
    Route::middleware('role:administrateur')->prefix('admin')->group(function () {
        Route::post('/campagnes/{campagne}/classement', [AdminClassementController::class, 'calculer']);
        Route::get('/campagnes/{campagne}/classement', [AdminClassementController::class, 'show']);
        Route::put('/candidatures/{candidature}/decision/motifs', [AdminClassementController::class, 'motifs']);

        // Lot 5b — acte de publication (irréversible). Bascule StatutPublicResolver
        // sur sa branche « publication existe » : le candidat voit sa décision.
        Route::post('/campagnes/{campagne}/publier', [PublicationController::class, 'publier']);

        /*
        |------------------------------------------------------------------
        | Lot 6a — Gestion & supervision (administrateur strict)
        |------------------------------------------------------------------
        */
        // Affectation d'un évaluateur (résout D-4a-1).
        Route::post('/affectations', [AffectationController::class, 'store']);
        // Vue de supervision transverse.
        Route::get('/candidatures', [CandidatureSupervisionController::class, 'index']);
        // Filières : activation / désactivation.
        Route::patch('/filieres/{filiere}', [AdminFiliereController::class, 'changerStatut']);
        // Campagnes : transitions d'état (ouvrir / clôturer).
        Route::patch('/campagnes/{campagne}', [AdminCampagneController::class, 'changerStatut']);
        // Journal d'audit : consultation (lecture seule, append-only garanti par le trigger).
        Route::get('/audit', [AuditController::class, 'index']);

        /*
        |------------------------------------------------------------------
        | Lot 6b — Actes exceptionnels tracés (administrateur strict)
        |------------------------------------------------------------------
        | Chaque acte exige un `motif` (422 sinon) et écrit 1 ligne
        | `journal_audit` ancienne_valeur -> nouvelle_valeur.
        */
        // Correction exceptionnelle : SEULE exception au verrouillage ADR-04.
        // Pré-publication uniquement (409 sinon, D-6b-2). Nouveau snapshot serveur.
        Route::post('/candidatures/{candidature}/correction/dossier', [CorrectionController::class, 'dossier']);
        Route::post('/candidatures/{candidature}/correction/entretien', [CorrectionController::class, 'entretien']);
        // Remplacement : post-publication. Promeut le 1er de la liste d'attente
        // (même filière, par rang) ; sortant -> indisponible, promu -> retenu.
        Route::post('/remplacements', [RemplacementController::class, 'store']);
        // Élimination manuelle : force `non_eligible` + motif (fraude, pièce non conforme).
        Route::post('/candidatures/{candidature}/elimination', [EliminationController::class, 'store']);
    });

    /*
    |----------------------------------------------------------------------
    | Lot 3a — Candidature en brouillon (rôle candidat, policy propriétaire)
    |----------------------------------------------------------------------
    | La candidature d'un autre candidat renvoie 404 (CandidaturePolicy ->
    | denyAsNotFound), jamais 403.
    */
    Route::middleware('role:candidat')->group(function () {
        // Lot 7 — profil candidat (état civil). Sans paramètre : agit toujours sur
        // le candidat du compte courant -> aucune surface vers le profil d'autrui.
        Route::get('/candidat/profil', [ProfilController::class, 'show']);
        Route::patch('/candidat/profil', [ProfilController::class, 'update']);

        Route::get('/candidature', [CandidatureController::class, 'courante']);
        Route::post('/candidatures', [CandidatureController::class, 'store']);

        // Lot 3b — téléchargement d'une pièce (dossier ou justificatif d'expérience).
        // Route à plat : l'id de pièce est global, la propriété est vérifiée par
        // PieceJustificativePolicy (-> 404 si pas propriétaire).
        Route::get('/pieces/{piece}/download', [PieceController::class, 'download']);

        Route::prefix('candidatures/{candidature}')->scopeBindings()->group(function () {
            // --- Lecture (toujours ouverte au propriétaire) ---
            Route::get('/', [CandidatureController::class, 'show']);
            Route::get('/pieces', [PieceController::class, 'index']);

            // --- Soumission (Lot 3c) : brouillon -> soumis. Gère lui-même
            //     le 409 "déjà soumise" (identique dans les deux branches). ---
            Route::post('/soumettre', SoumissionController::class);

            // --- Édition (3a/3b) : fermée en 409 dès que la candidature n'est
            //     plus en brouillon (middleware candidature.modifiable). ---
            Route::middleware('candidature.modifiable')->group(function () {
                Route::patch('/reponses', [ReponseFormulaireController::class, 'update']);
                Route::post('/confirmer-filiere', [CandidatureController::class, 'confirmerFiliere']);

                Route::post('/experiences', [ExperienceController::class, 'store']);
                Route::patch('/experiences/{experience}', [ExperienceController::class, 'update']);
                Route::delete('/experiences/{experience}', [ExperienceController::class, 'destroy']);

                Route::put('/classement', [ClassementController::class, 'update']);

                // Pièces justificatives (upload sécurisé, hors webroot). POST pour
                // l'upload (PHP ne parse le multipart que sur POST) ; UPSERT.
                Route::post('/pieces/{type}', [PieceDossierController::class, 'deposer'])
                    ->whereIn('type', ContraintesFichier::TYPES_DOSSIER);
                Route::delete('/pieces/{type}', [PieceDossierController::class, 'destroy'])
                    ->whereIn('type', ContraintesFichier::TYPES_DOSSIER);

                Route::post('/experiences/{experience}/justificatif', [JustificatifExperienceController::class, 'deposer']);
                Route::delete('/experiences/{experience}/justificatif', [JustificatifExperienceController::class, 'destroy']);
            });
        });
    });

    /*
    |----------------------------------------------------------------------
    | Lot 4a — Espace évaluateur : consultation & vérification du dossier
    |----------------------------------------------------------------------
    | evaluateur ET administrateur (ADR-10 : admin ⊇ évaluateur). L'évaluateur
    | ne voit que ses affectations ; l'admin voit tout. Dossier non affecté ->
    | 404 (CandidaturePolicy::voir/verifierCommeEvaluateur -> denyAsNotFound).
    */
    Route::middleware('role:evaluateur,administrateur')->prefix('evaluateur')->group(function () {
        Route::get('/candidatures', [DossierController::class, 'index']);
        Route::get('/pieces/{piece}/download', [PieceEvaluateurController::class, 'download']);

        Route::prefix('candidatures/{candidature}')->scopeBindings()->group(function () {
            Route::get('/', [DossierController::class, 'show']);
            Route::put('/verification', [VerificationController::class, 'update']);

            // Lot 4b — notation du volet Dossier (/65) + verrouillage réel.
            Route::get('/evaluation', [EvaluationController::class, 'show']);
            Route::put('/evaluation', [EvaluationController::class, 'update']);
            Route::post('/evaluation/validation', [EvaluationController::class, 'valider']);

            // Lot 4c — volet Entretien (/35) + verrouillage réel.
            Route::get('/entretien', [EntretienController::class, 'show']);
            Route::put('/entretien', [EntretienController::class, 'update']);
            Route::post('/entretien/validation', [EntretienController::class, 'valider']);
        });
    });
});
