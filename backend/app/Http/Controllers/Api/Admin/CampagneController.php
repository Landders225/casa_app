<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangerStatutCampagneRequest;
use App\Http\Resources\Admin\CampagneResource;
use App\Models\Campagne;
use App\Models\JournalAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * CAMPAGNES — liste (Lot 8d-1) + transitions d'état (Lot 6a) — administrateur strict.
 *
 *   GET   /api/admin/campagnes                    liste (Lot 8d-1 — comblait D-6a côté lecture)
 *   PATCH /api/admin/campagnes/{campagne}  { statut: 'ouverte' | 'cloturee' }
 *
 * Transitions autorisées : `brouillon → ouverte`, `ouverte → cloturee`. Toute
 * autre → 422. `brouillon → ouverte` refusée (409) si une autre campagne est
 * déjà ouverte (règle « une seule campagne ouverte » du Lot 3a). Une campagne
 * `cloturee` n'accepte plus de candidature (`POST /api/candidatures` → 409
 * « Aucune campagne ouverte », déjà en place).
 *
 * La CRÉATION de campagne (dates, places) est hors périmètre 6a (D-6a-2).
 */
class CampagneController extends Controller
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'brouillon' => ['ouverte'],
        'ouverte' => ['cloturee'],
        'cloturee' => [],
    ];

    /**
     * Liste blanche (Lot 8d-1) — non paginée (peu de campagnes). Alimente
     * l'écran de gestion des campagnes et le filtre `?campagne=` de
     * `GET /admin/candidatures`.
     */
    public function index(): AnonymousResourceCollection
    {
        $campagnes = Campagne::query()->orderByDesc('date_ouverture')->get();

        return CampagneResource::collection($campagnes);
    }

    public function changerStatut(ChangerStatutCampagneRequest $request, Campagne $campagne): JsonResponse
    {
        $actuel = $campagne->statut;
        $cible = $request->validated('statut');

        abort_unless(
            in_array($cible, self::TRANSITIONS[$actuel] ?? [], true),
            422,
            "Transition non autorisée : « {$actuel} » → « {$cible} ».",
        );

        if ($cible === 'ouverte') {
            abort_if(
                Campagne::query()->where('statut', 'ouverte')->whereKeyNot($campagne->id)->exists(),
                409,
                "Une autre campagne est déjà ouverte : une seule campagne peut l'être à la fois.",
            );
        }

        $campagne->update(['statut' => $cible]);

        JournalAudit::create([
            'auteur_id' => $request->user()->id,
            'role' => $request->user()->role,
            'action' => $cible === 'ouverte' ? 'Ouverture de campagne' : 'Clôture de campagne',
            'module' => 'Campagnes',
            'objet' => $campagne->nom,
            'ancienne_valeur' => ucfirst($actuel),
            'nouvelle_valeur' => $cible === 'ouverte' ? 'Ouverte' : 'Clôturée',
            'resultat' => 'Succès',
        ]);

        return response()->json([
            'data' => [
                'id' => $campagne->id,
                'nom' => $campagne->nom,
                'statut' => $campagne->statut,
            ],
        ]);
    }
}
