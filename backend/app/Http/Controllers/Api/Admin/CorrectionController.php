<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Eligibilite\ServiceEligibilite;
use App\Domain\Scoring\ServiceScoring;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CorrigerDossierRequest;
use App\Http\Requests\Admin\CorrigerEntretienRequest;
use App\Models\Candidature;
use App\Models\CritereEliminatoireDeclenche;
use App\Models\Grille;
use App\Models\JournalAudit;
use App\Models\ScoreRubriqueDossier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CORRECTION EXCEPTIONNELLE (Lot 6b) — administrateur strict.
 * SEULE exception au verrouillage réel d'ADR-04.
 *
 *   POST /api/admin/candidatures/{candidature}/correction/dossier
 *   POST /api/admin/candidatures/{candidature}/correction/entretien
 *
 * Règles :
 *  - `motif` OBLIGATOIRE (422 sinon) ;
 *  - l'évaluation doit être VERROUILLÉE (409 sinon — utilisez l'endpoint évaluateur) ;
 *  - campagne NON publiée (409 sinon, D-6b-2 : corriger une décision communiquée =
 *    contentieux ; après publication seul le remplacement modifie une décision) ;
 *  - le nouveau snapshot ÉCRASE l'ancien (MLD 1-1) — l'historique vit dans
 *    `journal_audit` (D-6b-1) ;
 *  - le dossier RELANCE `ServiceEligibilite` (les entrées ont changé) et met à jour
 *    `statut_eligibilite_interne` + retrace `critere_eliminatoire_declenche` ;
 *    `statut_interne` reste `evalue` (verdict ≠ position workflow) ;
 *  - 1 ligne `journal_audit` par acte, `ancienne_valeur` → `nouvelle_valeur`
 *    listant le SCORE + chaque champ modifié (D-6b : reconstituable à la lecture).
 */
class CorrectionController extends Controller
{
    public function __construct(
        private readonly ServiceScoring $scoring,
        private readonly ServiceEligibilite $eligibilite,
    ) {
    }

    public function dossier(CorrigerDossierRequest $request, Candidature $candidature): JsonResponse
    {
        $evaluation = $candidature->evaluationDossier;
        abort_if(
            $evaluation === null || ! $evaluation->valide,
            409,
            "L'évaluation du dossier n'est pas verrouillée : utilisez l'endpoint évaluateur normal.",
        );
        $this->exigerNonPublie($candidature);
        $membreId = $this->membreEquipeId($request);

        $reponse = $candidature->reponseFormulaire;
        $verification = $candidature->verification()->firstOrNew([]);

        $changements = [];
        $reponsesPatch = $request->reponses();
        foreach ($reponsesPatch as $champ => $nouveau) {
            $ancien = $reponse->{$champ};
            if ($this->norme($ancien) !== $this->norme($nouveau)) {
                $changements[$champ] = [$ancien, $nouveau];
            }
        }
        $verifPatch = $request->verification();
        foreach ($verifPatch as $champ => $nouveau) {
            $ancien = $verification?->{$champ};
            if ($this->norme($ancien) !== $this->norme($nouveau)) {
                $changements['verification.'.$champ] = [$ancien, $nouveau];
            }
        }
        $commentaireFourni = array_key_exists('commentaire_evaluateur', $request->validated());
        if ($commentaireFourni && $candidature->commentaire_evaluateur !== $request->validated('commentaire_evaluateur')) {
            $changements['commentaire_evaluateur'] = [$candidature->commentaire_evaluateur, $request->validated('commentaire_evaluateur')];
        }

        $scoreAvant = $evaluation->score_total;
        $eligibiliteAvant = $candidature->statut_eligibilite_interne;
        $grille = Grille::active();

        DB::transaction(function () use (
            $request, $candidature, $reponse, $verification, $reponsesPatch, $verifPatch,
            $commentaireFourni, $membreId, $grille, $evaluation, &$changements,
            $scoreAvant, $eligibiliteAvant,
        ) {
            if ($reponsesPatch !== []) {
                // Écriture DIRECTE sans toucher `reponse_formulaire.updated_at` :
                // la correction est un acte interne 🔴, le candidat ne perçoit rien
                // (règle reine, D-3b-7 — même discipline qu'au Lot 4b).
                DB::table('reponse_formulaire')
                    ->where('candidature_id', $candidature->id)
                    ->update($reponsesPatch);
            }

            if ($verifPatch !== []) {
                $verification->forceFill($verifPatch);
                $verification->verifie_par = $membreId;
                $verification->verifie_le = now();
                $verification->save();
            }
            if ($commentaireFourni) {
                $candidature->commentaire_evaluateur = $request->validated('commentaire_evaluateur');
                $candidature->saveQuietly();
            }

            $candidature->refresh()->load(['reponseFormulaire', 'verification', 'experiences']);
            $score = $this->scoring->calculer($candidature, $grille);

            $evaluation->forceFill([
                'grille_id' => $grille->id,
                'score_total' => $score->total,
                'valide_le' => now(),
                'valide_par' => $membreId,
            ])->save();

            ScoreRubriqueDossier::query()->where('evaluation_dossier_id', $candidature->id)->delete();
            ScoreRubriqueDossier::insert(array_map(fn ($rs) => [
                'evaluation_dossier_id' => $candidature->id,
                'rubrique_id' => $rs->rubriqueId,
                'score_obtenu' => round($rs->score, 4),
            ], array_values($score->rubriques)));

            // Relance complète de l'éligibilité (les entrées ont changé).
            $criteres = array_merge(
                $this->eligibilite->evaluerSoumission($candidature),
                $this->eligibilite->evaluerVerificationEvaluateur([
                    'diplome_verifie' => $candidature->verification?->diplome_verifie,
                    'nationalite_confirmee' => $candidature->verification?->nationalite_confirmee,
                ]),
            );
            $candidature->criteresEliminatoires()->delete();
            if ($criteres !== []) {
                $maintenant = now();
                CritereEliminatoireDeclenche::insert(array_map(fn ($c) => [
                    'id' => (string) Str::uuid(),
                    'candidature_id' => $candidature->id,
                    'code_critere' => $c->code,
                    'detail' => $c->detail,
                    'origine' => $c->origine,
                    'declenche_le' => $maintenant,
                ], $criteres));
            }
            $eligibiliteApres = $criteres === [] ? 'eligible' : 'non_eligible';
            $candidature->forceFill(['statut_eligibilite_interne' => $eligibiliteApres])->saveQuietly();

            if ($eligibiliteAvant !== $eligibiliteApres) {
                $changements['éligibilité'] = [$eligibiliteAvant, $eligibiliteApres];
            }

            JournalAudit::create([
                'auteur_id' => $request->user()->id,
                'role' => $request->user()->role,
                'action' => 'Correction exceptionnelle — évaluation dossier',
                'module' => 'Évaluation',
                'objet' => $candidature->numero_dossier,
                'ancienne_valeur' => $this->ligne('score dossier', $scoreAvant, '/65', $changements, 0),
                'nouvelle_valeur' => $this->ligne('score dossier', $score->total, '/65', $changements, 1),
                'motif' => $request->validated('motif'),
                'resultat' => 'Succès',
            ]);
        });

        $candidature->refresh();

        return response()->json(['data' => [
            'numero_dossier' => $candidature->numero_dossier,
            'score_dossier' => $candidature->evaluationDossier->score_total,
            'statut_eligibilite_interne' => $candidature->statut_eligibilite_interne,
            'champs_modifies' => array_keys($changements),
        ]]);
    }

    public function entretien(CorrigerEntretienRequest $request, Candidature $candidature): JsonResponse
    {
        $entretien = $candidature->entretien;
        abort_if(
            $entretien === null || $entretien->statut !== 'valide',
            409,
            "L'entretien n'est pas verrouillé : utilisez l'endpoint évaluateur normal.",
        );
        $this->exigerNonPublie($candidature);
        $membreId = $this->membreEquipeId($request);

        $data = $request->validated();
        $grille = Grille::active();
        $sousCriteres = $this->sousCriteresEntretien($grille);

        $notesAvant = $entretien->notes()->pluck('points_attribues', 'sous_critere_id');

        $changements = [];
        foreach (($data['notes'] ?? []) as $code => $points) {
            $sousCritere = $sousCriteres[$code] ?? abort(422, "Sous-critère d'entretien « {$code} » inconnu.");
            abort_if(
                (float) $points > (float) $sousCritere->max_points,
                422,
                "La note de « {$code} » ({$points}) dépasse son maximum ({$sousCritere->max_points}).",
            );
            $ancien = (float) ($notesAvant[$sousCritere->id] ?? 0);
            if ($ancien !== (float) $points) {
                $changements[$code] = [$ancien, (float) $points];
            }
        }
        $observationFournie = array_key_exists('observation', $data);
        if ($observationFournie && $entretien->observation !== $data['observation']) {
            $changements['observation'] = [$entretien->observation, $data['observation']];
        }

        $scoreAvant = $entretien->score_total;

        DB::transaction(function () use (
            $request, $candidature, $entretien, $data, $sousCriteres, $membreId, $grille,
            $observationFournie, &$changements, $scoreAvant,
        ) {
            foreach (($data['notes'] ?? []) as $code => $points) {
                DB::table('note_sous_critere_entretien')->updateOrInsert(
                    ['entretien_id' => $candidature->id, 'sous_critere_id' => $sousCriteres[$code]->id],
                    ['points_attribues' => $points],
                );
            }
            if ($observationFournie) {
                $entretien->observation = $data['observation'];
            }

            $score = $this->scoring->calculerEntretien(
                $candidature->fresh()->load('entretien.notes'),
                $grille,
            );

            $entretien->forceFill([
                'score_total' => $score->total,
                'grille_id' => $grille->id,
                'valide_le' => now(),
                'valide_par' => $membreId,
            ])->save();

            JournalAudit::create([
                'auteur_id' => $request->user()->id,
                'role' => $request->user()->role,
                'action' => 'Correction exceptionnelle — entretien',
                'module' => 'Entretien',
                'objet' => $candidature->numero_dossier,
                'ancienne_valeur' => $this->ligne('score entretien', $scoreAvant, '/35', $changements, 0),
                'nouvelle_valeur' => $this->ligne('score entretien', $score->total, '/35', $changements, 1),
                'motif' => $request->validated('motif'),
                'resultat' => 'Succès',
            ]);
        });

        $candidature->refresh();

        return response()->json(['data' => [
            'numero_dossier' => $candidature->numero_dossier,
            'score_entretien' => $candidature->entretien->score_total,
            'champs_modifies' => array_keys($changements),
        ]]);
    }

    // --- Helpers ---

    private function exigerNonPublie(Candidature $candidature): void
    {
        abort_if(
            $candidature->campagne->publication()->exists(),
            409,
            'Campagne publiée : la correction exceptionnelle est impossible (les décisions sont communiquées). Après publication, seul le remplacement modifie une décision.',
        );
    }

    private function membreEquipeId(Request $request): string
    {
        $id = $request->user()->membreEquipe?->id;
        abort_if($id === null, 422, 'Profil équipe incomplet : impossible de tracer l’auteur de la correction.');

        return $id;
    }

    /**
     * @return array<string, \App\Models\SousCritereEntretien>
     */
    private function sousCriteresEntretien(Grille $grille): array
    {
        $grille->loadMissing(['volets.rubriques.sousCriteres']);
        $volet = $grille->volets->firstWhere('code', 'entretien');
        abort_if($volet === null, 500, 'Grille sans volet « entretien ».');

        $map = [];
        foreach ($volet->rubriques as $rubrique) {
            foreach ($rubrique->sousCriteres as $sousCritere) {
                $map[$sousCritere->code] = $sousCritere;
            }
        }

        return $map;
    }

    private function norme(mixed $valeur): string
    {
        if ($valeur === null) {
            return '∅';
        }
        if (is_bool($valeur)) {
            return $valeur ? 'oui' : 'non';
        }

        return (string) $valeur;
    }

    /**
     * Une valeur d'audit lisible : « score dossier 64.2/65 · di02: aucune · mo04_note_etoiles: 4 ».
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changements
     */
    private function ligne(string $libelleScore, mixed $score, string $unite, array $changements, int $index): string
    {
        $parties = [sprintf('%s %s%s', $libelleScore, number_format((float) $score, 1, '.', ''), $unite)];
        foreach ($changements as $champ => $couple) {
            $parties[] = $champ.': '.$this->norme($couple[$index]);
        }

        return implode(' · ', $parties);
    }
}
