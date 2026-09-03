<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\CandidatureAdminResource;
use App\Models\Candidature;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * SUPERVISION des candidatures (Lot 6a) — `GET /api/admin/candidatures`,
 * administrateur strict. Vue transverse (toutes filières) pour choisir qui
 * affecter et suivre l'avancement.
 *
 *   ?statut_interne= &filiere=<code> &evaluateur=<id|non_affecte> &campagne=<id>
 *   &inclure_brouillons=1 &page=
 *
 * `CandidatureAdminResource` est 🔴 admin-only : jamais réutilisée côté candidat
 * ou évaluateur.
 */
class CandidatureSupervisionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Candidature::query()
            ->with(['candidat', 'filiere', 'evaluateur', 'evaluationDossier', 'entretien', 'decisionCandidature'])
            ->orderByDesc('date_soumission');

        if (! $request->boolean('inclure_brouillons')) {
            $query->where('statut_interne', '!=', 'brouillon');
        }
        if ($statut = $request->query('statut_interne')) {
            $query->where('statut_interne', $statut);
        }
        if ($filiere = $request->query('filiere')) {
            $query->whereHas('filiere', fn ($q) => $q->where('code', $filiere));
        }
        if ($evaluateur = $request->query('evaluateur')) {
            $evaluateur === 'non_affecte'
                ? $query->whereNull('evaluateur_id')
                : $query->where('evaluateur_id', $evaluateur);
        }
        if ($campagne = $request->query('campagne')) {
            $query->where('campagne_id', $campagne);
        }

        return CandidatureAdminResource::collection($query->paginate(20)->withQueryString());
    }
}
