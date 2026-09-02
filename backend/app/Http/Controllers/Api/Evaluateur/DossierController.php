<?php

namespace App\Http\Controllers\Api\Evaluateur;

use App\Http\Controllers\Controller;
use App\Http\Resources\Evaluateur\CandidatureEvaluateurResource;
use App\Http\Resources\Evaluateur\CandidatureListeEvaluateurResource;
use App\Models\Candidature;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DossierController extends Controller
{
    /**
     * Relations chargées pour la fiche complète.
     *
     * @var list<string>
     */
    private const RELATIONS_FICHE = [
        'candidat', 'filiere', 'campagne', 'evaluateur',
        'reponseFormulaire', 'experiences.pieceJustificative', 'piecesDossier',
        'verification.verifiePar', 'criteresEliminatoires',
        'evaluationDossier.grille', 'entretien.grille',
    ];

    /**
     * GET /api/evaluateur/candidatures — mes dossiers affectés.
     * Admin : tous les dossiers instructibles. Évaluateur : uniquement les siens.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = Candidature::query()
            ->with(['candidat', 'filiere', 'verification'])
            ->whereNotIn('statut_interne', ['brouillon', 'non_eligible'])
            ->orderByDesc('date_soumission');

        if (! $user->isAdministrateur()) {
            // membre_equipe garanti par le rôle evaluateur ; à défaut, aucun dossier.
            $query->where('evaluateur_id', $user->membreEquipe?->id ?? '00000000-0000-0000-0000-000000000000');
        }

        if ($statut = $request->query('statut_interne')) {
            $query->where('statut_interne', $statut);
        }
        if ($filiere = $request->query('filiere')) {
            $query->whereHas('filiere', fn ($q) => $q->where('code', $filiere));
        }

        return CandidatureListeEvaluateurResource::collection($query->paginate(20)->withQueryString());
    }

    /**
     * GET /api/evaluateur/candidatures/{candidature} — fiche complète.
     */
    public function show(Candidature $candidature): CandidatureEvaluateurResource
    {
        $this->authorize('voirCommeEvaluateur', $candidature);

        return new CandidatureEvaluateurResource($candidature->load(self::RELATIONS_FICHE));
    }
}
