<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\EvaluateurResource;
use App\Models\MembreEquipe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * ÉVALUATEURS — liste de lecture (Lot 8d-1) — administrateur strict.
 *
 *   GET /api/admin/evaluateurs           évaluateurs ACTIFS (cibles d'affectation/réattribution)
 *   GET /api/admin/evaluateurs?tous=1    + les désactivés, `actif` exposé (Lot 20b)
 *
 * Par défaut, alimente les sélecteurs CIBLES : `POST /admin/affectations`,
 * `POST /admin/reattributions`, `POST /admin/affectations/automatique` — un
 * compte désactivé ne peut recevoir ni affectation ni réattribution (refus
 * 422 côté contrôleur), il ne doit donc jamais y être proposé.
 *
 * `?tous=1` alimente UNIQUEMENT le FILTRE de la vue supervision
 * (`CandidaturesSupervision.jsx`) : un dossier peut rester affecté à un
 * évaluateur désactivé, l'admin doit pouvoir filtrer par lui (avec la mention
 * « désactivé ») — mais jamais le reproposer comme cible.
 *
 * Non paginé (équipe de taille réduite, cf. `GET /api/filieres`). Liste
 * BLANCHE stricte via `EvaluateurResource` : jamais l'e-mail ni une donnée de
 * compte `utilisateur` autre que `actif`.
 */
class EvaluateurController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $tous = $request->boolean('tous');

        $evaluateurs = MembreEquipe::query()
            ->whereHas('utilisateur', fn ($q) => $q->where('role', 'evaluateur')->when(! $tous, fn ($q2) => $q2->where('actif', true)))
            ->with('utilisateur')
            ->orderBy('prenom')
            ->get();

        return EvaluateurResource::collection($evaluateurs);
    }
}
