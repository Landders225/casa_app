<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Classement\ServiceClassement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EnregistrerMotifsRequest;
use App\Http\Resources\Admin\ClassementResource;
use App\Models\Campagne;
use App\Models\Candidature;
use App\Models\DecisionCandidature;
use App\Models\JournalAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CLASSEMENT & DÉCISIONS internes (Lot 5a) — administrateur strict.
 *
 *  POST /api/admin/campagnes/{campagne}/classement            calcule + persiste decision_candidature
 *  GET  /api/admin/campagnes/{campagne}/classement            lit le classement persisté (+ ?filiere=)
 *  PUT  /api/admin/candidatures/{candidature}/decision/motifs  motif_interne / motif_communicable
 *
 * Règles (cf. Étape 1) :
 *  - ServiceClassement lit les quotas EN BASE, somme les SCORES FIGÉS (ADR-04),
 *    trie selon l'ordre EXACT de scoring.js + départage final déterministe (D-5a-1) ;
 *  - `POST` idempotent tant qu'aucune `publication` n'existe (409 sinon) — c'est
 *    l'aperçu ; il upsert `rang`/`decision`, supprime les décisions devenues non
 *    classables, PRÉSERVE `motif_interne`/`motif_communicable` ;
 *  - un `non_eligible` évalué (dossier + entretien) reçoit `non_retenu` +
 *    `motif_interne = 'non éligible'` (🔴), `rang = null` (D-5a-4) ;
 *  - RIEN ne fuit au candidat : aucune `publication` n'est créée ici,
 *    `StatutPublicResolver` est inchangé (toujours « en_cours_de_traitement »).
 */
class ClassementController extends Controller
{
    public function __construct(private readonly ServiceClassement $classement)
    {
    }

    public function calculer(Request $request, Campagne $campagne): ClassementResource
    {
        abort_if(
            $campagne->publication()->exists(),
            409,
            'Le classement de cette campagne est publié : il n’est plus recalculable.',
        );

        $resultat = $this->classement->calculer($campagne);

        DB::transaction(function () use ($campagne, $resultat, $request) {
            $idsClasses = $resultat->candidatureIds();

            // Décisions des candidatures de la campagne qui ne sont plus classées.
            $idsCampagne = Candidature::query()->where('campagne_id', $campagne->id)->pluck('id');
            DecisionCandidature::query()
                ->whereIn('candidature_id', $idsCampagne)
                ->whereNotIn('candidature_id', $idsClasses)
                ->delete();

            foreach ($resultat->lignes as $ligne) {
                $decision = DecisionCandidature::firstOrNew(['candidature_id' => $ligne->candidatureId]);
                $decision->rang = $ligne->rang;
                $decision->decision = $ligne->decision;
                // Motif système pour un non-éligible — seulement si non déjà renseigné
                // (une note admin plus précise n'est pas écrasée au recalcul).
                if ($ligne->nonEligible && $decision->motif_interne === null) {
                    $decision->motif_interne = 'non éligible';
                }
                $decision->save();
            }

            JournalAudit::create([
                'auteur_id' => $request->user()->id,
                'role' => $request->user()->role,
                'action' => 'Calcul du classement',
                'module' => 'Classement',
                'objet' => $campagne->nom,
                'ancienne_valeur' => null,
                'nouvelle_valeur' => sprintf(
                    '%d filière(s), %d candidat(s) classé(s)',
                    count($resultat->filieres),
                    count($resultat->lignes),
                ),
                'resultat' => 'Succès',
            ]);
        });

        return new ClassementResource($this->etat($campagne, $request->query('filiere')));
    }

    public function show(Request $request, Campagne $campagne): ClassementResource
    {
        return new ClassementResource($this->etat($campagne, $request->query('filiere')));
    }

    public function motifs(EnregistrerMotifsRequest $request, Candidature $candidature): ClassementResource
    {
        abort_if(
            $candidature->campagne->publication()->exists(),
            409,
            'Le classement de cette campagne est publié : les motifs ne sont plus modifiables.',
        );

        $decision = $candidature->decisionCandidature;
        abort_if(
            $decision === null,
            404,
            'Aucune décision pour cette candidature : lancez d’abord le calcul du classement.',
        );

        $decision->fill($request->champsMotifs())->save();

        JournalAudit::create([
            'auteur_id' => $request->user()->id,
            'role' => $request->user()->role,
            'action' => 'Motif de non-retenue',
            'module' => 'Classement',
            'objet' => $candidature->numero_dossier,
            'ancienne_valeur' => null,
            'nouvelle_valeur' => $decision->motif_communicable !== null
                ? 'Motif communicable renseigné'
                : 'Message générique (aucun motif communicable)',
            'motif' => $decision->motif_interne,
            'resultat' => 'Succès',
        ]);

        return new ClassementResource($this->etat($candidature->campagne));
    }

    // --- Helpers ---

    /**
     * Classement persisté + scores/départage recalculés depuis les snapshots figés.
     *
     * @return array<string, mixed>
     */
    private function etat(Campagne $campagne, ?string $filiereCode = null): array
    {
        $campagne->loadMissing('filieres');

        $decisions = DecisionCandidature::query()
            ->whereIn(
                'candidature_id',
                Candidature::query()->where('campagne_id', $campagne->id)->select('id'),
            )
            ->with([
                'candidature.candidat', 'candidature.reponseFormulaire', 'candidature.experiences',
                'candidature.evaluationDossier', 'candidature.entretien', 'candidature.filiere',
            ])
            ->get();

        $parFiliere = $decisions->groupBy(fn ($d) => $d->candidature->filiere->code);

        $filieres = [];
        foreach ($campagne->filieres as $filiere) {
            if ($filiereCode !== null && $filiere->code !== $filiereCode) {
                continue;
            }

            // Rang croissant ; non-éligibles (rang NULL) en fin ; puis numéro de dossier.
            $groupe = ($parFiliere[$filiere->code] ?? collect())
                ->sortBy(fn ($d) => sprintf('%020d|%s', $d->rang ?? PHP_INT_MAX, $d->candidature->numero_dossier));

            $filieres[] = [
                'filiere' => ['code' => $filiere->code, 'nom' => $filiere->nom],
                'quota' => (int) $filiere->pivot->quota,
                'retenus' => $groupe->where('decision', 'retenu')->count(),
                'liste_attente' => $groupe->where('decision', 'liste_attente')->count(),
                'non_retenus' => $groupe->where('decision', 'non_retenu')->count(),
                'lignes' => $groupe->map(fn ($d) => $this->ligne($d))->values()->all(),
            ];
        }

        return [
            'campagne' => ['id' => $campagne->id, 'nom' => $campagne->nom],
            'version_algorithme' => ServiceClassement::VERSION,
            'liste_attente_taille' => ServiceClassement::TAILLE_LISTE_ATTENTE,
            'calcule' => $decisions->isNotEmpty(),
            'publie' => $campagne->publication()->exists(),
            'filieres' => $filieres,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ligne(DecisionCandidature $decision): array
    {
        $candidature = $decision->candidature;
        $departage = $this->classement->departage($candidature);

        return [
            'candidature_id' => $candidature->id,
            'numero_dossier' => $candidature->numero_dossier,
            'rang' => $decision->rang,
            'decision' => $decision->decision,
            'motif_interne' => $decision->motif_interne,
            'motif_communicable' => $decision->motif_communicable,
            'non_eligible' => $candidature->statut_eligibilite_interne === 'non_eligible',
            'candidat' => [
                'prenom' => $candidature->candidat->prenom,
                'nom' => $candidature->candidat->nom,
                'sexe' => $candidature->candidat->sexe,
                'ville_residence' => $candidature->candidat->ville_residence,
            ],
            'score_dossier' => $candidature->evaluationDossier?->score_total,
            'score_entretien' => $candidature->entretien?->score_total,
            'score_final' => number_format($this->classement->scoreFinal($candidature), 1, '.', ''),
            'departage' => [
                'mixite_f' => $departage['mixite_f'],
                'vulnerabilite' => $departage['vulnerabilite'],
                'experience_secteur' => $departage['experience_secteur'],
                'mo04' => $departage['mo04'],
            ],
        ];
    }
}
