<?php

namespace App\Http\Controllers\Api\Evaluateur;

use App\Domain\Eligibilite\ServiceEligibilite;
use App\Http\Controllers\Controller;
use App\Http\Requests\Evaluateur\VerifierDossierRequest;
use App\Http\Resources\Evaluateur\CandidatureEvaluateurResource;
use App\Models\Candidature;
use App\Models\CritereEliminatoireDeclenche;
use App\Models\JournalAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PUT /api/evaluateur/candidatures/{candidature}/verification — saisie / mise à
 * jour de la vérification du dossier (nationalité, diplôme).
 *
 * Peut déclencher les critères éliminatoires connus SEULEMENT après vérification
 * (SC.04=cepe, nationalité non confirmée) via
 * ServiceEligibilite::evaluerVerificationEvaluateur().
 *
 * Effet sur l'état : `statut_eligibilite_interne` bascule
 * (`eligible` <-> `non_eligible`), mais `statut_interne` reste `en_instruction`
 * (le dossier ne quitte pas l'espace de l'évaluateur — cf. Étape 1 Q2). Le
 * candidat n'apprend rien : `verification_dossier` et
 * `critere_eliminatoire_declenche` sont 🔴, et `StatutPublicResolver` mappe
 * toujours `non_eligible -> "en_cours_de_traitement"`.
 */
class VerificationController extends Controller
{
    public function __construct(private readonly ServiceEligibilite $eligibilite) {}

    public function update(VerifierDossierRequest $request, Candidature $candidature): CandidatureEvaluateurResource
    {
        $this->authorize('verifierCommeEvaluateur', $candidature);

        abort_unless(
            $candidature->statut_interne === 'en_instruction',
            409,
            "Ce dossier n'est pas au statut « en instruction » : la vérification n'est pas modifiable.",
        );

        $membreEquipeId = $request->user()->membreEquipe?->id;

        DB::transaction(function () use ($request, $candidature, $membreEquipeId) {
            $eligibiliteAvant = $candidature->statut_eligibilite_interne;

            // a. verification_dossier upsert (firstOrNew pose déjà candidature_id)
            $verification = $candidature->verification()->firstOrNew([]);
            $verification->fill($request->champsVerification());
            $verification->verifie_par = $membreEquipeId;
            $verification->verifie_le = now();
            $verification->save();

            // b. critères d'origine évaluateur (idempotent : on retrace)
            $criteres = $this->eligibilite->evaluerVerificationEvaluateur([
                'diplome_verifie' => $verification->diplome_verifie,
                'nationalite_confirmee' => $verification->nationalite_confirmee,
            ]);

            $candidature->criteresEliminatoires()
                ->where('origine', 'verification_evaluateur')
                ->delete();

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

            // c. verdict d'éligibilité : reste au moins un critère (toute origine) ?
            $resteUnCritere = $candidature->criteresEliminatoires()->exists();
            $eligibiliteApres = $resteUnCritere ? 'non_eligible' : 'eligible';

            // statut_interne INCHANGÉ ('en_instruction') — cf. Q2.
            $candidature->forceFill(['statut_eligibilite_interne' => $eligibiliteApres])->saveQuietly();

            JournalAudit::create([
                'auteur_id' => $request->user()->id,
                'role' => $request->user()->role,
                'action' => 'Vérification du dossier',
                'module' => 'Évaluation',
                'objet' => $candidature->numero_dossier,
                'ancienne_valeur' => $eligibiliteAvant,
                'nouvelle_valeur' => $eligibiliteApres,
                'resultat' => 'Succès',
            ]);
        });

        return new CandidatureEvaluateurResource($candidature->fresh()->load([
            'candidat', 'filiere', 'campagne', 'evaluateur',
            'reponseFormulaire', 'experiences.pieceJustificative', 'piecesDossier',
            'verification.verifiePar', 'criteresEliminatoires',
            'evaluationDossier.grille', 'entretien.grille',
        ]));
    }
}
