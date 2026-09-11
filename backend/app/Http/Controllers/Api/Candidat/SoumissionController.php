<?php

namespace App\Http\Controllers\Api\Candidat;

use App\Domain\Candidature\ValidateurCompletude;
use App\Domain\Eligibilite\ServiceEligibilite;
use App\Http\Controllers\Controller;
use App\Models\Candidature;
use App\Models\CritereEliminatoireDeclenche;
use App\Models\JournalAudit;
use App\Notifications\CandidatureSoumise;
use App\Services\StatutPublicResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * POST /api/candidatures/{candidature}/soumettre — passage brouillon -> soumis.
 *
 * RÈGLE REINE (séquence a, ADR-03) : la réponse HTTP est STRICTEMENT IDENTIQUE
 * que le candidat soit éligible ou non — même code, même corps, même
 * `statut_public`. L'éligibilité est calculée et persistée (pour l'évaluateur),
 * jamais renvoyée.
 *
 * Distinction :
 *  - dossier INCOMPLET  -> 422 avec le détail (la complétude du candidat, dicible) ;
 *  - dossier NON ÉLIGIBLE -> réponse neutre identique (jamais dit).
 */
class SoumissionController extends Controller
{
    public function __construct(
        private readonly ValidateurCompletude $completude,
        private readonly ServiceEligibilite $eligibilite,
        private readonly StatutPublicResolver $statutPublic,
    ) {}

    public function __invoke(Request $request, Candidature $candidature): JsonResponse
    {
        $this->authorize('update', $candidature);

        // Déjà soumise : même réponse quelle que soit l'éligibilité interne.
        abort_if(! $candidature->estBrouillon(), 409, 'Cette candidature a déjà été soumise.');

        $candidature->load([
            'candidat.utilisateur', 'campagne', 'reponseFormulaire', 'experiences', 'piecesDossier',
        ]);

        // 1) Complétude — dicible (c'est la propre complétude du candidat).
        $erreurs = $this->completude->verifier($candidature);
        if ($erreurs !== []) {
            return response()->json([
                'message' => 'Le dossier de candidature est incomplet.',
                'errors' => $erreurs,
            ], 422);
        }

        // 2) Éligibilité — calculée, persistée, JAMAIS renvoyée.
        DB::transaction(function () use ($candidature, $request) {
            $criteres = $this->eligibilite->evaluerSoumission($candidature);
            $eligible = $criteres === [];

            if (! $eligible) {
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

            $candidature->forceFill([
                'statut_interne' => $eligible ? 'soumis' : 'non_eligible',
                'statut_eligibilite_interne' => $eligible ? 'eligible' : 'non_eligible',
                'date_soumission' => now(),
            ])->saveQuietly();

            JournalAudit::create([
                'auteur_id' => $request->user()->id,
                'role' => $request->user()->role,
                'action' => 'Soumission de candidature',
                'module' => 'Candidatures',
                'objet' => $candidature->numero_dossier,
                'nouvelle_valeur' => $candidature->statut_interne, // table 🔴 (évaluateur/admin)
                'resultat' => 'Succès',
            ]);
        });

        // 2bis) Confirmation — APPEL UNIQUE, hors de toute branche conditionnelle
        // sur l'éligibilité (RÈGLE REINE, ADR-33) : le seul champ qui varie entre
        // deux destinataires est `numero_dossier`. Ne bloque jamais la réponse
        // (ShouldQueue).
        $candidature->candidat->utilisateur->notify(new CandidatureSoumise($candidature->numero_dossier));

        // 3) Réponse NEUTRE — surface minimale (séquence a). `statut_public` via
        // le VRAI resolver (ADR-03) : 'soumis' comme 'non_eligible' -> "en_cours_de_traitement".
        return response()->json([
            'data' => [
                'numero_dossier' => $candidature->numero_dossier,
                'statut_public' => $this->statutPublic->resoudre($candidature)->statutPublic,
            ],
        ]);
    }
}
