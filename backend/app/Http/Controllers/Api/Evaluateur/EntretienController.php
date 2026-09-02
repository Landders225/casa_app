<?php

namespace App\Http\Controllers\Api\Evaluateur;

use App\Domain\Scoring\ServiceScoring;
use App\Http\Controllers\Controller;
use App\Http\Requests\Evaluateur\EnregistrerEntretienRequest;
use App\Http\Resources\Evaluateur\EntretienResource;
use App\Models\Candidature;
use App\Models\Entretien;
use App\Models\Grille;
use App\Models\JournalAudit;
use App\Models\SousCritereEntretien;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Volet ENTRETIEN (/35) + verrouillage réel (Lot 4c).
 *
 *  GET   /api/evaluateur/candidatures/{c}/entretien             état + aperçu OU snapshot
 *  PUT   /api/evaluateur/candidatures/{c}/entretien             brouillon : planif / présence / observation / sous-notes
 *  POST  /api/evaluateur/candidatures/{c}/entretien/validation  validation définitive → verrouillage
 *
 * Règles (cf. Étape 1) :
 *  - précondition : `candidature.dossier_verrouille = true` (le dossier /65 doit
 *    être validé — Lot 4b) — 409 sinon ;
 *  - `ServiceScoring::calculerEntretien` lit le barème (sous-critères + maxima)
 *    EN BASE (ADR-06). `note_sous_critere_entretien` = sous-notes brutes,
 *    mutables tant que `statut != 'valide'` ; `entretien.score_total` + `grille_id`
 *    = snapshot figé à la validation (ADR-04) ;
 *  - après validation : `statut = 'valide'`, verrouillé (409 sur toute
 *    modification) ; `candidature.statut_interne` INCHANGÉ (reste `evalue`) —
 *    l'avancement fin vit sur `entretien.statut` (D-4c-1) ;
 *  - `presence = 'absent'` → 12 sous-notes figées à 0, non saisissables ;
 *  - une sous-note > max du sous-critère → 422 explicite (pas de clamp silencieux).
 */
class EntretienController extends Controller
{
    public function __construct(private readonly ServiceScoring $scoring)
    {
    }

    public function show(Candidature $candidature): EntretienResource
    {
        $this->authorize('evaluerCommeEvaluateur', $candidature);

        return new EntretienResource($this->etat($candidature));
    }

    public function update(EnregistrerEntretienRequest $request, Candidature $candidature): EntretienResource
    {
        $this->authorize('evaluerCommeEvaluateur', $candidature);
        $this->exigerDossierVerrouille($candidature);

        $entretien = $candidature->entretien;
        abort_if($entretien !== null && $entretien->statut === 'valide', 409, 'Entretien déjà validé et verrouillé.');

        $donnees = $request->validated();

        if ($entretien === null) {
            foreach (['date', 'heure', 'lieu'] as $champ) {
                abort_unless(
                    array_key_exists($champ, $donnees),
                    422,
                    "La planification de l'entretien requiert une date, une heure et un lieu.",
                );
            }
        }

        DB::transaction(function () use ($request, $candidature, $entretien, $donnees) {
            $entretien ??= new Entretien(['candidature_id' => $candidature->id]);

            $entretien->fill(Arr::only($donnees, ['date', 'heure', 'lieu', 'presence', 'observation']));

            if (! $entretien->exists) {
                $entretien->evaluateur_id = $request->user()->membreEquipe?->id ?? $candidature->evaluateur_id;
            }
            $entretien->statut = $entretien->presence !== null ? 'realise' : 'planifie';
            $entretien->save();

            if (array_key_exists('notes', $donnees) && $donnees['notes'] !== []) {
                abort_unless(
                    $entretien->presence === 'present',
                    422,
                    $entretien->presence === 'absent'
                        ? 'Candidat absent : les sous-notes sont figées à 0 et ne sont pas saisissables.'
                        : 'Renseignez la présence (« présent ») avant de saisir les sous-notes.',
                );

                $sousCriteres = $this->sousCriteresParCode(Grille::active());

                foreach ($donnees['notes'] as $code => $points) {
                    $sousCritere = $sousCriteres[$code]
                        ?? abort(422, "Sous-critère d'entretien « {$code} » inconnu.");

                    abort_if(
                        (float) $points > (float) $sousCritere->max_points,
                        422,
                        "La note de « {$code} » ({$points}) dépasse son maximum ({$sousCritere->max_points}).",
                    );

                    DB::table('note_sous_critere_entretien')->updateOrInsert(
                        ['entretien_id' => $candidature->id, 'sous_critere_id' => $sousCritere->id],
                        ['points_attribues' => $points],
                    );
                }
            }
        });

        return new EntretienResource($this->etat($candidature->fresh()));
    }

    public function valider(Request $request, Candidature $candidature): EntretienResource
    {
        $this->authorize('evaluerCommeEvaluateur', $candidature);
        $this->exigerDossierVerrouille($candidature);

        $entretien = $candidature->entretien;
        abort_if($entretien !== null && $entretien->statut === 'valide', 409, 'Entretien déjà validé et verrouillé.');
        abort_if(
            $entretien === null || $entretien->statut === 'planifie' || $entretien->presence === null,
            422,
            "Indiquez la présence du candidat avant de valider l'entretien.",
        );

        $grille = Grille::active();
        $membreEquipeId = $request->user()->membreEquipe?->id ?? $candidature->evaluateur_id;

        DB::transaction(function () use ($request, $candidature, $entretien, $grille, $membreEquipeId) {
            $sousCriteres = $this->sousCriteresParCode($grille);

            // Fige les 12 lignes : sous-note existante ou 0 (absente / candidat absent).
            $existantes = $entretien->presence === 'absent'
                ? collect()
                : $entretien->notes()->pluck('points_attribues', 'sous_critere_id');

            DB::table('note_sous_critere_entretien')->where('entretien_id', $candidature->id)->delete();
            DB::table('note_sous_critere_entretien')->insert(
                collect($sousCriteres)->map(fn (SousCritereEntretien $sc) => [
                    'entretien_id' => $candidature->id,
                    'sous_critere_id' => $sc->id,
                    'points_attribues' => (float) ($existantes[$sc->id] ?? 0),
                ])->values()->all(),
            );

            $score = $this->scoring->calculerEntretien(
                $candidature->fresh()->load('entretien.notes'),
                $grille,
            );

            $entretien->forceFill([
                'statut' => 'valide',
                'score_total' => $score->total,
                'grille_id' => $grille->id,
                'valide_le' => now(),
                'valide_par' => $membreEquipeId,
            ])->save();

            JournalAudit::create([
                'auteur_id' => $request->user()->id,
                'role' => $request->user()->role,
                'action' => "Validation d'entretien",
                'module' => 'Entretien',
                'objet' => $candidature->numero_dossier,
                'ancienne_valeur' => null,
                'nouvelle_valeur' => number_format($score->total, 1, '.', '').'/'.number_format($score->voletMax, 0),
                'resultat' => 'Succès',
            ]);
        });

        return new EntretienResource($this->etat($candidature->fresh()));
    }

    // --- Helpers ---

    private function exigerDossierVerrouille(Candidature $candidature): void
    {
        abort_unless(
            $candidature->dossier_verrouille,
            409,
            "Le dossier doit être évalué et verrouillé (Lot 4b) avant de conduire l'entretien.",
        );
    }

    /**
     * @return array<string, SousCritereEntretien>
     */
    private function sousCriteresParCode(Grille $grille): array
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

    /**
     * @return array<string, mixed>
     */
    private function etat(Candidature $candidature): array
    {
        $candidature->loadMissing([
            'entretien.notes.sousCritere', 'entretien.grille.volets', 'entretien.validePar', 'entretien.evaluateur',
        ]);
        $entretien = $candidature->entretien;

        if ($entretien === null) {
            return [
                'dossier_verrouille' => (bool) $candidature->dossier_verrouille,
                'entretien' => null,
            ];
        }

        $verrouille = $entretien->statut === 'valide';
        $grille = $verrouille ? $entretien->grille : Grille::active();
        $score = $this->scoring->calculerEntretien($candidature, $grille);

        return [
            'dossier_verrouille' => (bool) $candidature->dossier_verrouille,
            'entretien' => [
                'statut' => $entretien->statut,
                'verrouille' => $verrouille,
                'source' => $verrouille ? 'snapshot' : 'apercu',
                'date' => $entretien->date?->toDateString(),
                'heure' => $entretien->heure,
                'lieu' => $entretien->lieu,
                'presence' => $entretien->presence,
                'observation' => $entretien->observation,
                'evaluateur' => $entretien->evaluateur ? [
                    'id' => $entretien->evaluateur->id,
                    'prenom' => $entretien->evaluateur->prenom,
                    'nom' => $entretien->evaluateur->nom,
                ] : null,
                // Snapshot : score_total canonique stocké ; sinon aperçu recalculé.
                'score_total' => $verrouille
                    ? $entretien->score_total
                    : number_format($score->total, 1, '.', ''),
                'volet_max' => $score->voletMax,
                'grille' => $grille ? ['version' => $grille->version, 'label' => $grille->label] : null,
                'rubriques' => array_values(array_map(fn ($rs) => [
                    'code' => $rs->code,
                    'label' => $rs->label,
                    'score_obtenu' => number_format($rs->score, 1, '.', ''),
                    'max' => $rs->max,
                ], $score->rubriques)),
                'sous_notes' => array_map(fn ($sn) => [
                    'code' => $sn->code,
                    'rubrique_code' => $sn->rubriqueCode,
                    'label' => $sn->label,
                    'points_attribues' => number_format($sn->points, 1, '.', ''),
                    'max' => $sn->max,
                ], $score->sousNotes),
                'valide_le' => $entretien->valide_le?->toIso8601String(),
                'valide_par' => $entretien->validePar ? [
                    'id' => $entretien->validePar->id,
                    'prenom' => $entretien->validePar->prenom,
                    'nom' => $entretien->validePar->nom,
                ] : null,
            ],
        ];
    }
}
