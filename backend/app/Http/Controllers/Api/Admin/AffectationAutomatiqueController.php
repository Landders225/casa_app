<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Affectation\ServiceAffectationAutomatique;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AffectationAutomatiqueRequest;
use App\Http\Requests\Admin\ApercuAffectationAutomatiqueRequest;
use Illuminate\Http\JsonResponse;

/**
 * AFFECTATION AUTOMATIQUE par critères (ADR-45) — administrateur strict.
 *
 *   POST /api/admin/affectations/automatique/apercu   → répartition prévue, RIEN n'est écrit
 *   POST /api/admin/affectations/automatique          → exécution (transaction unique)
 *
 * Toute la logique (sélection, garde-fous, répartition, journal) vit dans
 * `ServiceAffectationAutomatique` ; les refus sont rendus par
 * `AffectationAutomatiqueRefusee` (`{ message, code }`). La réponse ne
 * contient que des nombres et les évaluateurs — jamais un dossier ni un
 * candidat.
 */
class AffectationAutomatiqueController extends Controller
{
    public function __construct(private readonly ServiceAffectationAutomatique $service) {}

    public function apercu(ApercuAffectationAutomatiqueRequest $request): JsonResponse
    {
        $plan = $this->service->apercu(
            $request->criteres(),
            $request->evaluateurIds(),
            (int) $request->validated('nombre_correspondants'),
            $request->cibles(),
        );

        return response()->json(['data' => $plan->toArray()]);
    }

    public function store(AffectationAutomatiqueRequest $request): JsonResponse
    {
        $plan = $this->service->executer(
            $request->criteres(),
            $request->evaluateurIds(),
            (int) $request->validated('nombre_correspondants'),
            (int) $request->validated('nombre_affectables'),
            $request->user(),
            $request->cibles(),
            $request->repartitionAttendue(),
            $request->nonAffectesAttendu(),
        );

        // Lot 3 (correctif de revue) : dossiers RÉELLEMENT attribués — avec
        // un reliquat sous cibles (ADR-48), `nombreAffectables` seul
        // surcompte les dossiers restés volontairement non affectés.
        return response()->json(['data' => [...$plan->toArray(), 'affectees' => $plan->nombreAffectables - $plan->nonAffectes]]);
    }
}
