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
 * Verrouillage RÉEL de l'entretien + non-recalcul après changement de grille
 * (ADR-04), miroir de VerrouillageEvaluationTest (Lot 4b).
 */
class VerrouillageEntretienTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private User $evaluateur;
    private Candidature $candidature;

    /** @var array<string,int> Cas « fort » → 33,0 */
    private array $notesFort = [
        'PRES.01' => 3, 'PRES.02' => 2, 'PRES.03' => 3,
        'REL.01' => 3, 'REL.02' => 2, 'REL.03' => 4,
        'EO.01' => 3, 'EO.02' => 3, 'EO.03' => 2,
        'MOE.01' => 3, 'MOE.02' => 3, 'MOE.03' => 2,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->seedBareme();
        $this->evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
        $this->candidature = $this->verrouillerDossier(
            $this->candidatureAffectee($this->creerCandidat('cand@casa-demo.ci'), $this->evaluateur),
        );
    }

    private function url(string $suffixe = ''): string
    {
        return "/api/evaluateur/candidatures/{$this->candidature->id}/entretien{$suffixe}";
    }

    private function valider(): void
    {
        $this->actingAs($this->evaluateur)->putJson($this->url(), [
            'date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Le Plateau',
            'presence' => 'present', 'notes' => $this->notesFort,
        ])->assertOk();
        $this->actingAs($this->evaluateur)->postJson($this->url('/validation'))->assertOk();
    }

    public function test_apres_validation_toute_modification_est_refusee_409(): void
    {
        $this->valider();

        $this->actingAs($this->evaluateur)->putJson($this->url(), ['notes' => ['PRES.01' => 0]])
            ->assertStatus(409);
        $this->actingAs($this->evaluateur)->putJson($this->url(), ['observation' => 'après coup'])
            ->assertStatus(409);
        $this->actingAs($this->evaluateur)->postJson($this->url('/validation'))
            ->assertStatus(409);
    }

    public function test_apres_validation_le_GET_renvoie_le_snapshot_fige(): void
    {
        $this->valider();

        $this->actingAs($this->evaluateur)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.entretien.source', 'snapshot')
            ->assertJsonPath('data.entretien.verrouille', true)
            ->assertJsonPath('data.entretien.score_total', '33.0')
            ->assertJsonPath('data.entretien.grille.version', 1);
    }

    public function test_le_score_fige_ne_bouge_pas_quand_une_grille_v2_est_activee(): void
    {
        $this->valider();

        $grilleV2 = $this->activerGrilleClone();
        $this->assertSame($grilleV2->id, Grille::active()->id);

        // 1) L'entretien verrouillé garde exactement son score et sa grille v1.
        $this->actingAs($this->evaluateur)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.entretien.source', 'snapshot')
            ->assertJsonPath('data.entretien.score_total', '33.0')
            ->assertJsonPath('data.entretien.grille.version', 1);

        $this->assertDatabaseHas('entretien', [
            'candidature_id' => $this->candidature->id,
            'score_total' => '33.0',
        ]);

        // 2) Un NOUVEL entretien est noté avec la grille v2 (maxima +1 par sous-critère).
        $autreEval = $this->creerEvaluateur('eval2@casa-demo.ci');
        $autre = $this->verrouillerDossier(
            $this->candidatureAffectee($this->creerCandidat('cand2@casa-demo.ci'), $autreEval),
        );

        $this->actingAs($autreEval)->putJson("/api/evaluateur/candidatures/{$autre->id}/entretien", [
            'date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Le Plateau', 'presence' => 'present',
        ])->assertOk()
            ->assertJsonPath('data.entretien.grille.version', 2)
            ->assertJsonPath('data.entretien.source', 'apercu');
    }

    public function test_la_ligne_de_sous_note_reste_intacte_apres_verrouillage(): void
    {
        $this->valider();

        $avant = DB::table('note_sous_critere_entretien')
            ->where('entretien_id', $this->candidature->id)
            ->orderBy('sous_critere_id')
            ->get()
            ->toJson();

        // Toute tentative de PUT est refusée (409) — rien ne change en base.
        $this->actingAs($this->evaluateur)->putJson($this->url(), ['notes' => ['PRES.01' => 0, 'REL.03' => 0]])
            ->assertStatus(409);

        $apres = DB::table('note_sous_critere_entretien')
            ->where('entretien_id', $this->candidature->id)
            ->orderBy('sous_critere_id')
            ->get()
            ->toJson();

        $this->assertSame($avant, $apres);
    }
}
