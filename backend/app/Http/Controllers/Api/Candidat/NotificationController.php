<?php

namespace App\Http\Controllers\Api\Candidat;

use App\Http\Controllers\Controller;
use App\Http\Resources\Candidat\NotificationCandidatResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Historique in-app des notifications (Lot 12c) — canal `database` des 4
 * Notifications du Lot 12b (ADR-33). `role:candidat` (groupe de routes),
 * strictement scopé à `$request->user()` — jamais de binding implicite sur la
 * table `notifications` (qui appartiendrait à n'importe quel utilisateur) :
 * chaque méthode repart de `$request->user()->notifications()` /
 * `->unreadNotifications()`, donc une notification d'un autre candidat est
 * INVISIBLE ici (404, jamais 403 — on ne confirme pas son existence).
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return NotificationCandidatResource::collection(
            $request->user()->notifications()->paginate(20)->withQueryString(),
        );
    }

    /** Compteur léger pour le badge — évite de charger une page complète juste pour un nombre. */
    public function compteur(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['non_lues' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    public function marquerLue(Request $request, string $id): NotificationCandidatResource
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return new NotificationCandidatResource($notification);
    }

    /** Une seule requête UPDATE — pas une boucle de N marquages. */
    public function marquerToutLu(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => 'Toutes les notifications ont été marquées comme lues.']);
    }
}
