<?php

namespace Tests\Feature\Evaluateur;

use App\Http\Controllers\Api\Evaluateur\EntretienController;
use App\Models\Candidature;
use App\Models\Grille;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    /**
     * Correctif de revue (Lot 20b), miroir de la même preuve côté dossier /65
     * (`VerrouillageEvaluationTest`) — relecture sous verrou d'`evaluateur_id`
     * dans `EntretienController::valider`.
     */
    public function test_validation_entretien_refusee_si_le_dossier_a_ete_reattribue_sous_le_verrou(): void
    {
        $this->actingAs($this->evaluateur)->putJson($this->url(), [
            'date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Le Plateau',
            'presence' => 'present', 'notes' => $this->notesFort,
        ])->assertOk();

        $stale = $this->candidature->fresh()->load('entretien.notes');

        $autreEvaluateur = $this->creerEvaluateur('eval2@casa-demo.ci');
        DB::table('candidature')->where('id', $this->candidature->id)
            ->update(['evaluateur_id' => $autreEvaluateur->membreEquipe->id]);

        $this->actingAs($this->evaluateur);
        $request = Request::create('/', 'POST');
        $request->setUserResolver(fn () => $this->evaluateur);

        try {
            app(EntretienController::class)->valider($request, $stale);
            $this->fail('La validation aurait dû être refusée (403) : le dossier appartient maintenant à un autre évaluateur.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertDatabaseMissing('entretien', ['candidature_id' => $this->candidature->id, 'statut' => 'valide']);
    }

    /**
     * Correctif de revue : `$entretien->statut` (ligne 169 du contrôleur) est
     * lu AVANT la transaction, jamais relu sous verrou — contrairement à
     * `evaluateur_id` (commit 107e4c1). `entretien.candidature_id` n'étant
     * PAS une clé primaire exposée à un risque d'INSERT en double (le
     * contrôleur fait une mise à jour, `->save()`), une double validation
     * simultanée ne plante pas (contrairement au cas `EvaluationController`,
     * commit 2d239c5) — mais SANS ce correctif, elle écrit une SECONDE ligne
     * `journal_audit` « Validation d'entretien » et réécrit `valide_le`/
     * `valide_par` sans raison (vérifié empiriquement : 1 -> 2 lignes).
     * `$stale` eager-charge `entretien.notes` AVANT la première validation
     * pour forcer la course (sinon l'accès paresseux de `$candidature->entretien`
     * masquerait le problème en interrogeant la base à l'instant de l'appel).
     */
    public function test_entretien_refuse_proprement_une_double_validation_simultanee(): void
    {
        $this->actingAs($this->evaluateur)->putJson($this->url(), [
            'date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Le Plateau',
            'presence' => 'present', 'notes' => $this->notesFort,
        ])->assertOk();

        $stale = $this->candidature->fresh()->load('entretien.notes');

        $this->actingAs($this->evaluateur)->postJson($this->url('/validation'))->assertOk();

        $this->actingAs($this->evaluateur);
        $request = Request::create('/', 'POST');
        $request->setUserResolver(fn () => $this->evaluateur);

        try {
            app(EntretienController::class)->valider($request, $stale);
            $this->fail('La seconde validation aurait dû être refusée proprement (409).');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(1, \App\Models\JournalAudit::where('action', "Validation d'entretien")->count());
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
