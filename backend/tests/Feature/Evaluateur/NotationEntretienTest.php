<?php

namespace Tests\Feature\Evaluateur;

use App\Models\Candidature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Flux du volet Entretien (Lot 4c) : planification minimale → présence /
 * sous-notes (brouillon) → validation définitive → snapshot figé + verrouillage.
 */
class NotationEntretienTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private User $evaluateur;

    private Candidature $candidature;

    /** @var array<string,int> Cas « fort » de scoring.js → 33,0 */
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

    private function planifier(): void
    {
        $this->actingAs($this->evaluateur)->putJson($this->url(), [
            'date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Le Plateau',
        ])->assertOk();
    }

    public function test_entretien_bloque_tant_que_le_dossier_n_est_pas_verrouille(): void
    {
        $autre = $this->candidatureAffectee($this->creerCandidat('c2@casa-demo.ci'), $this->evaluateur);

        $this->actingAs($this->evaluateur)
            ->putJson("/api/evaluateur/candidatures/{$autre->id}/entretien", [
                'date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Le Plateau',
            ])->assertStatus(409);
        $this->actingAs($this->evaluateur)
            ->postJson("/api/evaluateur/candidatures/{$autre->id}/entretien/validation")
            ->assertStatus(409);
    }

    public function test_GET_sans_entretien_renvoie_null(): void
    {
        $this->actingAs($this->evaluateur)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.dossier_verrouille', true)
            ->assertJsonPath('data.entretien', null);
    }

    public function test_planification_cree_l_entretien_au_statut_planifie(): void
    {
        $this->actingAs($this->evaluateur)->putJson($this->url(), [
            'date' => '2026-07-06', 'heure' => '09:00', 'lieu' => '2 Plateaux Vallons',
        ])->assertOk()
            ->assertJsonPath('data.entretien.statut', 'planifie')
            ->assertJsonPath('data.entretien.lieu', '2 Plateaux Vallons')
            ->assertJsonPath('data.entretien.verrouille', false);

        $this->assertDatabaseHas('entretien', [
            'candidature_id' => $this->candidature->id,
            'statut' => 'planifie',
            'evaluateur_id' => $this->evaluateur->membreEquipe->id,
        ]);
    }

    public function test_premiere_requete_sans_date_heure_lieu_rejetee_422(): void
    {
        $this->actingAs($this->evaluateur)->putJson($this->url(), ['presence' => 'present'])
            ->assertStatus(422);
    }

    public function test_lieu_invalide_rejete_422(): void
    {
        $this->actingAs($this->evaluateur)->putJson($this->url(), [
            'date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Cocody',
        ])->assertStatus(422)->assertJsonValidationErrors('lieu');
    }

    public function test_presence_et_sous_notes_passent_le_statut_a_realise(): void
    {
        $this->planifier();

        $this->actingAs($this->evaluateur)->putJson($this->url(), [
            'presence' => 'present',
            'observation' => 'Candidat posé, discours clair.',
            'notes' => $this->notesFort,
        ])->assertOk()
            ->assertJsonPath('data.entretien.statut', 'realise')
            ->assertJsonPath('data.entretien.source', 'apercu')
            ->assertJsonPath('data.entretien.presence', 'present')
            ->assertJsonPath('data.entretien.score_total', '33.0')
            ->assertJsonCount(4, 'data.entretien.rubriques')
            ->assertJsonCount(12, 'data.entretien.sous_notes');

        $this->assertSame(12, DB::table('note_sous_critere_entretien')
            ->where('entretien_id', $this->candidature->id)->count());
    }

    public function test_sous_notes_refusees_si_presence_non_renseignee_422(): void
    {
        $this->planifier();

        $this->actingAs($this->evaluateur)->putJson($this->url(), ['notes' => ['PRES.01' => 2]])
            ->assertStatus(422);
    }

    public function test_sous_note_au_dela_du_max_rejetee_422(): void
    {
        $this->planifier();
        $this->actingAs($this->evaluateur)->putJson($this->url(), ['presence' => 'present'])->assertOk();

        // REL.03 max = 4
        $this->actingAs($this->evaluateur)->putJson($this->url(), ['notes' => ['REL.03' => 5]])
            ->assertStatus(422);
    }

    public function test_candidat_absent_sous_notes_non_saisissables_422(): void
    {
        $this->planifier();
        $this->actingAs($this->evaluateur)->putJson($this->url(), ['presence' => 'absent'])->assertOk()
            ->assertJsonPath('data.entretien.statut', 'realise');

        $this->actingAs($this->evaluateur)->putJson($this->url(), ['notes' => ['PRES.01' => 2]])
            ->assertStatus(422);
    }

    public function test_validation_impossible_sans_presence_422(): void
    {
        $this->planifier();

        $this->actingAs($this->evaluateur)->postJson($this->url('/validation'))
            ->assertStatus(422);
    }

    public function test_validation_fige_le_snapshot_et_verrouille(): void
    {
        $this->planifier();
        $this->actingAs($this->evaluateur)->putJson($this->url(), [
            'presence' => 'present', 'notes' => $this->notesFort,
        ])->assertOk();

        $this->actingAs($this->evaluateur)->postJson($this->url('/validation'))
            ->assertOk()
            ->assertJsonPath('data.entretien.statut', 'valide')
            ->assertJsonPath('data.entretien.verrouille', true)
            ->assertJsonPath('data.entretien.source', 'snapshot')
            ->assertJsonPath('data.entretien.score_total', '33.0')
            ->assertJsonPath('data.entretien.valide_par.prenom', 'Eval');

        $this->assertDatabaseHas('entretien', [
            'candidature_id' => $this->candidature->id,
            'statut' => 'valide',
            'score_total' => '33.0',
            'valide_par' => $this->evaluateur->membreEquipe->id,
        ]);
        $this->assertNotNull($this->candidature->fresh()->entretien->grille_id);
        $this->assertSame(12, DB::table('note_sous_critere_entretien')
            ->where('entretien_id', $this->candidature->id)->count());

        // statut_interne INCHANGÉ (D-4c-1).
        $this->assertSame('evalue', $this->candidature->fresh()->statut_interne);

        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $this->evaluateur->id,
            'action' => "Validation d'entretien",
            'module' => 'Entretien',
            'nouvelle_valeur' => '33.0/35',
        ]);
    }

    public function test_validation_candidat_absent_fige_un_zero(): void
    {
        $this->planifier();
        $this->actingAs($this->evaluateur)->putJson($this->url(), ['presence' => 'absent'])->assertOk();

        $this->actingAs($this->evaluateur)->postJson($this->url('/validation'))
            ->assertOk()
            ->assertJsonPath('data.entretien.score_total', '0.0');

        $this->assertSame(12, DB::table('note_sous_critere_entretien')
            ->where('entretien_id', $this->candidature->id)->count());
        $this->assertSame(0.0, (float) DB::table('note_sous_critere_entretien')
            ->where('entretien_id', $this->candidature->id)->sum('points_attribues'));
    }

    public function test_sous_note_non_renseignee_compte_comme_zero(): void
    {
        $this->planifier();
        // Seulement 2 sous-notes sur 10.
        $this->actingAs($this->evaluateur)->putJson($this->url(), [
            'presence' => 'present', 'notes' => ['PRES.01' => 3, 'REL.03' => 4],
        ])->assertOk()
            ->assertJsonPath('data.entretien.score_total', '7.0'); // 3 + 4, le reste à 0

        $this->actingAs($this->evaluateur)->postJson($this->url('/validation'))->assertOk()
            ->assertJsonPath('data.entretien.score_total', '7.0');
        $this->assertSame(12, DB::table('note_sous_critere_entretien')
            ->where('entretien_id', $this->candidature->id)->count());
    }

    public function test_isolation_evaluateur_non_affecte_404(): void
    {
        $autre = $this->creerEvaluateur('autre@casa-demo.ci');

        $this->actingAs($autre)->getJson($this->url())->assertStatus(404);
        $this->actingAs($autre)->putJson($this->url(), ['date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Le Plateau'])->assertStatus(404);
        $this->actingAs($autre)->postJson($this->url('/validation'))->assertStatus(404);
    }

    public function test_admin_peut_conduire_un_entretien_non_affecte_a_lui(): void
    {
        $admin = $this->creerAdmin('admin@casa-demo.ci');

        $this->actingAs($admin)->putJson($this->url(), [
            'date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Le Plateau', 'presence' => 'present',
        ])->assertOk();
        $this->actingAs($admin)->postJson($this->url('/validation'))->assertOk()
            ->assertJsonPath('data.entretien.verrouille', true);
    }

    public function test_le_candidat_ne_peut_pas_appeler_les_routes_entretien(): void
    {
        $candidat = $this->creerCandidat('autre-cand@casa-demo.ci');

        $this->actingAs($candidat)->getJson($this->url())->assertStatus(403);
        $this->actingAs($candidat)->putJson($this->url(), ['date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Le Plateau'])->assertStatus(403);
        $this->actingAs($candidat)->postJson($this->url('/validation'))->assertStatus(403);
    }
}
