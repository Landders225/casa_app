<?php

namespace Tests\Feature\Evaluateur;

use App\Models\Candidature;
use App\Models\Grille;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Verrouillage RÉEL (cas F) + non-recalcul après changement de grille (ADR-04).
 */
class VerrouillageEvaluationTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private User $evaluateur;

    private Candidature $candidature;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->seedBareme();
        $this->evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
        $this->candidature = $this->candidatureAffectee(
            $this->creerCandidat('cand@casa-demo.ci'),
            $this->evaluateur,
        );
        $this->poserVerification($this->candidature, 'bac', true);
    }

    private function url(string $suffixe = ''): string
    {
        return "/api/evaluateur/candidatures/{$this->candidature->id}/evaluation{$suffixe}";
    }

    private function valider(): void
    {
        $this->actingAs($this->evaluateur)->putJson($this->url(), ['mo04_note_etoiles' => 4])->assertOk();
        $this->actingAs($this->evaluateur)->postJson($this->url('/validation'))->assertOk();
    }

    public function test_apres_validation_toute_modification_est_refusee_409(): void
    {
        $this->valider();

        $this->actingAs($this->evaluateur)->putJson($this->url(), ['mo04_note_etoiles' => 1])
            ->assertStatus(409);
        $this->actingAs($this->evaluateur)->postJson($this->url('/validation'))
            ->assertStatus(409);

        // La vérification 4a se ferme aussi (statut_interne = 'evalue').
        $this->actingAs($this->evaluateur)
            ->putJson("/api/evaluateur/candidatures/{$this->candidature->id}/verification", ['diplome_verifie' => 'cepe'])
            ->assertStatus(409);
    }

    public function test_apres_validation_le_GET_renvoie_le_snapshot_fige(): void
    {
        $this->valider();

        $this->actingAs($this->evaluateur)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.source', 'snapshot')
            ->assertJsonPath('data.verrouille', true)
            ->assertJsonPath('data.score_total', '45.0')
            ->assertJsonPath('data.grille.version', 1);
    }

    public function test_le_score_fige_ne_bouge_pas_quand_une_grille_v2_est_activee(): void
    {
        $this->valider();
        $scoreV1 = '45.0';

        $grilleV2 = $this->activerGrilleV2();
        $this->assertSame($grilleV2->id, Grille::active()->id);

        // 1) L'évaluation verrouillée garde EXACTEMENT son score et sa grille v1.
        $this->actingAs($this->evaluateur)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.source', 'snapshot')
            ->assertJsonPath('data.score_total', $scoreV1)
            ->assertJsonPath('data.grille.version', 1);

        $this->assertDatabaseHas('evaluation_dossier', [
            'candidature_id' => $this->candidature->id,
            'score_total' => $scoreV1,
        ]);

        // 2) Un NOUVEAU dossier est noté avec la grille v2 (poids +1 par rubrique).
        $autreEval = $this->creerEvaluateur('eval2@casa-demo.ci');
        $autre = $this->candidatureAffectee($this->creerCandidat('cand2@casa-demo.ci'), $autreEval);
        $this->poserVerification($autre, 'bac', true);

        $this->actingAs($autreEval)
            ->getJson("/api/evaluateur/candidatures/{$autre->id}/evaluation")
            ->assertOk()
            ->assertJsonPath('data.grille.version', 2)
            ->assertJsonPath('data.source', 'apercu');
    }

    /**
     * Clone la grille active en v2 (poids de chaque rubrique + 1) et l'active.
     */
    private function activerGrilleV2(): Grille
    {
        $v1 = Grille::active()->load('volets.rubriques.items.options');
        DB::table('grille')->where('id', $v1->id)->update(['actif' => false]);

        $v2 = Grille::create([
            'version' => 2,
            'label' => 'Grille v2 (test)',
            'date_effet' => now()->toDateString(),
            'actif' => true,
            'created_at' => now(),
        ]);

        foreach ($v1->volets as $volet) {
            $nvVolet = $v2->volets()->create([
                'code' => $volet->code,
                'label' => $volet->label,
                'max_points' => $volet->max_points,
            ]);

            foreach ($volet->rubriques as $rubrique) {
                $nvRubrique = $nvVolet->rubriques()->create([
                    'code' => $rubrique->code,
                    'label' => $rubrique->label,
                    'max_points' => (float) $rubrique->max_points + 1,
                    'ordre' => $rubrique->ordre,
                ]);

                foreach ($rubrique->items as $item) {
                    $nvItem = $nvRubrique->items()->create([
                        'code' => $item->code,
                        'label' => $item->label,
                        'type' => $item->type,
                        'max_points' => $item->max_points,
                        'notation_evaluateur' => $item->notation_evaluateur,
                        'notee' => $item->notee,
                        'eliminatoire' => $item->eliminatoire,
                        'eliminatoire_groupe' => $item->eliminatoire_groupe,
                    ]);

                    foreach ($item->options as $option) {
                        $nvItem->options()->create([
                            'valeur' => $option->valeur,
                            'label' => $option->label,
                            'points' => $option->points,
                            'eliminatoire' => $option->eliminatoire,
                        ]);
                    }
                }
            }
        }

        return $v2->fresh();
    }
}
