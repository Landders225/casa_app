<?php

namespace App\Http\Controllers\Api\Candidat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Candidat\CreerCandidatureRequest;
use App\Http\Resources\CandidatureCandidatResource;
use App\Models\Campagne;
use App\Models\Candidature;
use App\Models\Filiere;
use App\Services\GenerateurNumeroDossier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CandidatureController extends Controller
{
    /**
     * Relations toujours chargées pour la Resource candidat.
     *
     * @var list<string>
     */
    private const RELATIONS = [
        'filiere', 'campagne', 'reponseFormulaire',
        'experiences.pieceJustificative', 'classement.filiere', 'piecesDossier',
    ];

    /**
     * GET /api/candidature — ma candidature (la plus récente).
     *
     * On NE filtre PAS sur `campagne.statut = 'ouverte'` : après la clôture d'une
     * campagne par l'admin (Lot 6a), le candidat doit toujours pouvoir consulter
     * son dossier et — après publication (Lot 5b) — sa décision. Seule la
     * CRÉATION (`store`) exige une campagne ouverte.
     */
    public function courante(Request $request): CandidatureCandidatResource
    {
        $candidat = $request->user()->candidat;

        $candidature = $candidat?->candidatures()->latest()->first();

        abort_if($candidature === null, 404, 'Aucune candidature.');

        return new CandidatureCandidatResource($candidature->load(self::RELATIONS));
    }

    /**
     * POST /api/candidatures — créer ma candidature pour la campagne ouverte.
     *
     * Porte temporairement le geste d'inscription (cf. docs/ADR.md ADR-13) :
     * un vrai lot inscription/profil reste à faire.
     */
    public function store(CreerCandidatureRequest $request): JsonResponse
    {
        $candidat = $request->user()->candidat;
        abort_if($candidat === null, 422, 'Profil candidat incomplet : impossible de créer une candidature.');

        $ouvertes = Campagne::query()->where('statut', 'ouverte')->get();
        abort_if($ouvertes->count() !== 1, 409, 'Aucune campagne ouverte pour candidater.');
        $campagne = $ouvertes->first();

        $existe = Candidature::query()
            ->where('candidat_id', $candidat->id)
            ->where('campagne_id', $campagne->id)
            ->exists();
        abort_if($existe, 409, 'Vous avez déjà une candidature pour cette campagne.');

        /** @var Filiere|null $filiere */
        $filiere = Filiere::query()
            ->where('id', $request->validated('filiere_id'))
            ->whereHas('campagnes', fn ($q) => $q->where('campagne.id', $campagne->id))
            ->first();
        abort_if($filiere === null, 422, "Cette filière n'est pas ouverte pour la campagne en cours.");

        // Lot 6a : une filière désactivée par l'admin n'accepte plus de candidature.
        abort_if(! $filiere->actif, 422, "Cette filière n'accepte pas de candidature actuellement.");

        $candidature = DB::transaction(function () use ($candidat, $campagne, $filiere) {
            $candidature = Candidature::create([
                'candidat_id' => $candidat->id,
                'campagne_id' => $campagne->id,
                'filiere_id' => $filiere->id,
                'numero_dossier' => app(GenerateurNumeroDossier::class)->generer($campagne),
                'cqp_confirme' => false,
            ]);

            // 1-1 strict (MCD) : jeu de réponses vide dès la création.
            $candidature->reponseFormulaire()->create([]);

            // Classement pré-rempli : filière visée en rang 1, puis les autres
            // filières de la campagne par ordre alphabétique (fidèle à inscription.html).
            $autres = $campagne->filieres()
                ->where('filiere.id', '!=', $filiere->id)
                ->orderBy('filiere.nom')
                ->pluck('filiere.id')
                ->all();

            foreach (array_merge([$filiere->id], $autres) as $i => $filiereId) {
                $candidature->classement()->create(['filiere_id' => $filiereId, 'rang' => $i + 1]);
            }

            return $candidature;
        });

        // fresh() : recharge les valeurs par défaut de la base (statut_interne
        // = 'brouillon') que l'instance en mémoire ne connaît pas après create().
        return (new CandidatureCandidatResource($candidature->fresh(self::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/candidatures/{candidature}
     */
    public function show(Candidature $candidature): CandidatureCandidatResource
    {
        $this->authorize('view', $candidature);

        return new CandidatureCandidatResource($candidature->load(self::RELATIONS));
    }

    /**
     * POST /api/candidatures/{candidature}/confirmer-filiere
     * Irréversible ; idempotent.
     */
    public function confirmerFiliere(Candidature $candidature): CandidatureCandidatResource
    {
        $this->authorize('update', $candidature);

        if (! $candidature->cqp_confirme) {
            $candidature->update(['cqp_confirme' => true]);
        }

        return new CandidatureCandidatResource($candidature->load(self::RELATIONS));
    }
}
