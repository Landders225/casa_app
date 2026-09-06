<?php

namespace App\Http\Controllers\Api\Evaluateur;

use App\Domain\Scoring\ScoreDossier;
use App\Domain\Scoring\ServiceScoring;
use App\Http\Controllers\Controller;
use App\Http\Requests\Evaluateur\EnregistrerEvaluationRequest;
use App\Http\Resources\Evaluateur\EvaluationDossierResource;
use App\Models\Candidature;
use App\Models\EvaluationDossier;
use App\Models\Grille;
use App\Models\JournalAudit;
use App\Models\ScoreRubriqueDossier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Notation du VOLET DOSSIER (/65) + verrouillage réel (Lot 4b).
 *
 *  GET   /api/evaluateur/candidatures/{c}/evaluation             aperçu OU snapshot
 *  PUT   /api/evaluateur/candidatures/{c}/evaluation             brouillon (note MO.04 + commentaire)
 *  POST  /api/evaluateur/candidatures/{c}/evaluation/validation  validation définitive → verrouillage
 *
 * Règles (cf. Étape 1, Q1-Q7) :
 *  - le score est calculé 100 % serveur par ServiceScoring, qui lit le barème
 *    EN BASE (ADR-06). Tant que non validé : aucun `evaluation_dossier`, le
 *    score est un aperçu recalculé à la volée (ADR-04) ;
 *  - à la validation : snapshot figé (`score_total` + 6 `score_rubrique_dossier`)
 *    + `grille_id`, `dossier_verrouille = true`, `statut_interne = 'evalue'`,
 *    ligne `journal_audit` « Validation d'évaluation ». Ré-ouverture impossible
 *    (409) — la correction exceptionnelle admin est un lot ultérieur ;
 *  - `statut_eligibilite_interne` n'est PAS touché ici et l'éligibilité n'est
 *    pas relancée (D-4b-3) : ses entrées n'ont pas changé depuis 3c / 4a ;
 *  - confidentialité : tout passe par EvaluationDossierResource (🔴), jamais
 *    renvoyé au candidat.
 */
class EvaluationController extends Controller
{
    public function __construct(private readonly ServiceScoring $scoring) {}

    /**
     * Aperçu (dossier non verrouillé) ou snapshot figé (verrouillé).
     */
    public function show(Candidature $candidature): EvaluationDossierResource
    {
        $this->authorize('evaluerCommeEvaluateur', $candidature);

        $evaluation = $candidature->evaluationDossier;
        if ($evaluation !== null && $evaluation->valide) {
            return new EvaluationDossierResource($this->snapshot($candidature, $evaluation));
        }

        return new EvaluationDossierResource($this->apercu($candidature));
    }

    /**
     * Brouillon : note MO.04 (0..5) + commentaire qualitatif. Renvoie l'aperçu
     * recalculé.
     */
    public function update(EnregistrerEvaluationRequest $request, Candidature $candidature): EvaluationDossierResource
    {
        $this->authorize('evaluerCommeEvaluateur', $candidature);
        $this->assurerModifiable($candidature);

        $donnees = $request->validated();

        if (array_key_exists('mo04_note_etoiles', $donnees)) {
            // Écriture DIRECTE (pas via le modèle) : la note de l'évaluateur ne
            // « modifie » pas les déclarations du candidat — `reponse_formulaire.updated_at`
            // reste inchangé, le candidat ne perçoit rien (règle reine, D-3b-7).
            DB::table('reponse_formulaire')
                ->where('candidature_id', $candidature->id)
                ->update(['mo04_note_etoiles' => $donnees['mo04_note_etoiles']]);
        }

        if (array_key_exists('commentaire_evaluateur', $donnees)) {
            // `commentaire_evaluateur` (🔴) n'est pas exposé au candidat ; l'écrire
            // sans toucher `updated_at` non plus, par cohérence.
            DB::table('candidature')
                ->where('id', $candidature->id)
                ->update(['commentaire_evaluateur' => $donnees['commentaire_evaluateur']]);
        }

        return new EvaluationDossierResource($this->apercu($candidature->fresh()));
    }

    /**
     * Validation définitive → verrouillage réel.
     */
    public function valider(Request $request, Candidature $candidature): EvaluationDossierResource
    {
        $this->authorize('evaluerCommeEvaluateur', $candidature);
        $this->assurerModifiable($candidature);

        $verification = $candidature->verification;
        abort_if(
            $verification === null
                || $verification->nationalite_confirmee === null
                || $verification->diplome_verifie === null,
            422,
            'La vérification du dossier (nationalité confirmée et diplôme vérifié) doit être complétée avant la notation.',
        );

        $reponse = $candidature->reponseFormulaire;
        abort_if(
            $reponse === null || $reponse->mo04_note_etoiles === null,
            422,
            'La note de motivation (MO.04) doit être saisie avant la validation définitive.',
        );

        $membreEquipeId = $request->user()->membreEquipe?->id;
        $grille = Grille::active();

        $evaluation = DB::transaction(function () use ($request, $candidature, $grille, $membreEquipeId) {
            $score = $this->scoring->calculer($candidature, $grille);

            $evaluation = EvaluationDossier::create([
                'candidature_id' => $candidature->id,
                'grille_id' => $grille->id,
                'score_total' => $score->total,
                'valide' => true,
                'valide_le' => now(),
                'valide_par' => $membreEquipeId,
            ]);

            ScoreRubriqueDossier::insert(array_map(fn ($rs) => [
                'evaluation_dossier_id' => $candidature->id,
                'rubrique_id' => $rs->rubriqueId,
                'score_obtenu' => round($rs->score, 4),
            ], array_values($score->rubriques)));

            $candidature->forceFill([
                'dossier_verrouille' => true,
                'dossier_verrouille_le' => now(),
                'dossier_verrouille_par' => $membreEquipeId,
                'statut_interne' => 'evalue',
                'date_evaluation' => now()->toDateString(),
            ])->saveQuietly();

            JournalAudit::create([
                'auteur_id' => $request->user()->id,
                'role' => $request->user()->role,
                'action' => "Validation d'évaluation",
                'module' => 'Évaluation',
                'objet' => $candidature->numero_dossier,
                'ancienne_valeur' => null,
                'nouvelle_valeur' => number_format($score->total, 1, '.', '').'/'.number_format($score->voletMax, 0),
                'resultat' => 'Succès',
            ]);

            return $evaluation;
        });

        return new EvaluationDossierResource($this->snapshot($candidature->refresh(), $evaluation));
    }

    // --- Helpers ---

    /**
     * 409 si l'évaluation est déjà verrouillée ou si le dossier n'est plus en
     * instruction (soumis / non traité / déjà évalué).
     */
    private function assurerModifiable(Candidature $candidature): void
    {
        abort_if(
            $candidature->dossier_verrouille || $candidature->evaluationDossier?->valide,
            409,
            'Évaluation déjà validée et verrouillée. Seule une correction exceptionnelle (administrateur) peut la rouvrir.',
        );

        abort_unless(
            $candidature->statut_interne === 'en_instruction',
            409,
            'Ce dossier n’est pas au statut « en instruction » : la notation n’est pas ouverte.',
        );
    }

    /**
     * Aperçu recalculé sur la grille ACTIVE (dossier non verrouillé).
     *
     * @return array<string, mixed>
     */
    private function apercu(Candidature $candidature): array
    {
        $grille = Grille::active();
        $score = $this->scoring->calculer($candidature, $grille);

        return [
            'verrouille' => false,
            'source' => 'apercu',
            'grille' => ['version' => $grille->version, 'label' => $grille->label],
            'mo04_note_etoiles' => $candidature->reponseFormulaire?->mo04_note_etoiles,
            'commentaire_evaluateur' => $candidature->commentaire_evaluateur,
            'score_total' => number_format($score->total, 1, '.', ''),
            'volet_max' => $score->voletMax,
            'rubriques' => $this->rubriquesApercu($score),
            'valide_le' => null,
            'valide_par' => null,
        ];
    }

    /**
     * Snapshot figé (dossier verrouillé) — jamais recalculé (ADR-04).
     *
     * @return array<string, mixed>
     */
    private function snapshot(Candidature $candidature, EvaluationDossier $evaluation): array
    {
        $evaluation->loadMissing(['scoresRubriques.rubrique', 'grille.volets', 'validePar']);
        $voletMax = (float) ($evaluation->grille->volets->firstWhere('code', 'dossier')?->max_points ?? 65);

        return [
            'verrouille' => true,
            'source' => 'snapshot',
            'grille' => ['version' => $evaluation->grille->version, 'label' => $evaluation->grille->label],
            'mo04_note_etoiles' => $candidature->reponseFormulaire?->mo04_note_etoiles,
            'commentaire_evaluateur' => $candidature->commentaire_evaluateur,
            'score_total' => $evaluation->score_total,
            'volet_max' => $voletMax,
            'rubriques' => $evaluation->scoresRubriques
                ->sortBy(fn ($sr) => $sr->rubrique->ordre)
                ->map(fn ($sr) => [
                    'code' => $sr->rubrique->code,
                    'label' => $sr->rubrique->label,
                    'score_obtenu' => $sr->score_obtenu,
                    'max' => (float) $sr->rubrique->max_points,
                ])->values(),
            'valide_le' => $evaluation->valide_le?->toIso8601String(),
            'valide_par' => $evaluation->validePar ? [
                'id' => $evaluation->validePar->id,
                'prenom' => $evaluation->validePar->prenom,
                'nom' => $evaluation->validePar->nom,
            ] : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rubriquesApercu(ScoreDossier $score): array
    {
        return array_values(array_map(fn ($rs) => [
            'code' => $rs->code,
            'label' => $rs->label,
            'score_obtenu' => number_format($rs->score, 4, '.', ''),
            'max' => $rs->max,
        ], $score->rubriques));
    }
}
