<?php

namespace Tests\Feature\Admin;

use App\Models\Campagne;
use App\Models\Candidature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Élimination manuelle (Lot 6b, ADR-15) — l'admin force `non_eligible` + motif.
 * Trace `critere_eliminatoire_declenche` d'origine `decision_administrative`.
 * Pas de promotion automatique (≠ remplacement). Après publication : décision
 * `non_retenu` générique, indiscernable d'un non-retenu ordinaire.
 */
class EliminationManuelleTest extends TestCase
{
    use CreeContexteClassement;
    use RefreshDatabase;

    private Campagne $campagne;

    private User $admin;

    private User $evaluateur;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedContexteClassement();
        $this->campagne = Campagne::ouverte()->firstOrFail();
        $this->admin = $this->creerAdmin('admin@casa-demo.ci');
        $this->evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
    }

    private function url(Candidature $c): string
    {
        return "/api/admin/candidatures/{$c->id}/elimination";
    }

    private function candidatUser(Candidature $c): User
    {
        return User::findOrFail($c->fresh()->candidat->utilisateur_id);
    }

    private function decision(Candidature $c): ?string
    {
        return DB::table('decision_candidature')->where('candidature_id', $c->id)->value('decision');
    }

    public function test_elimination_force_non_eligible_avec_trace(): void
    {
        $candidature = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 55.0, 28.0);

        $this->actingAs($this->admin)->postJson($this->url($candidature), [
            'motif' => 'Pièce d’identité falsifiée détectée lors du contrôle.',
        ])->assertOk()
            ->assertJsonPath('data.numero_dossier', $candidature->numero_dossier)
            ->assertJsonPath('data.statut_eligibilite_interne', 'non_eligible');

        $this->assertSame('non_eligible', $candidature->fresh()->statut_eligibilite_interne);

        $this->assertDatabaseHas('critere_eliminatoire_declenche', [
            'candidature_id' => $candidature->id,
            'code_critere' => 'decision_administrative',
            'origine' => 'decision_administrative',
            'detail' => 'Pièce d’identité falsifiée détectée lors du contrôle.',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'action' => 'Élimination manuelle',
            'module' => 'Candidatures',
            'objet' => $candidature->numero_dossier,
        ]);
    }

    public function test_elimination_sans_motif_422(): void
    {
        $candidature = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 55.0, 28.0);

        $this->actingAs($this->admin)->postJson($this->url($candidature), [])
            ->assertStatus(422)->assertJsonValidationErrors('motif');

        $this->assertSame('eligible', $candidature->fresh()->statut_eligibilite_interne);
    }

    public function test_elimination_bascule_la_decision_existante_en_non_retenu(): void
    {
        $candidature = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/classement")->assertOk();
        $this->assertSame('retenu', $this->decision($candidature));

        $this->actingAs($this->admin)->postJson($this->url($candidature), [
            'motif' => 'Fraude avérée.',
        ])->assertOk();

        $this->assertSame('non_retenu', $this->decision($candidature));
        $this->assertDatabaseHas('journal_audit', [
            'action' => 'Élimination manuelle',
            'nouvelle_valeur' => 'éligibilité: non_eligible · décision: non_retenu',
        ]);
    }

    public function test_pas_de_promotion_automatique_de_la_liste_d_attente(): void
    {
        DB::table('campagne_filiere')
            ->where('campagne_id', $this->campagne->id)
            ->where('filiere_id', $this->idFiliere('cuisine'))
            ->update(['quota' => 1]);

        $retenu = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $attente = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 40.0, 20.0);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/classement")->assertOk();
        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/publier")->assertOk();

        $this->actingAs($this->admin)->postJson($this->url($retenu), [
            'motif' => 'Fraude — pas un désistement.',
        ])->assertOk();

        // La liste d'attente n'est PAS promue (fraude ≠ désistement, D-6b Q7).
        $this->assertSame('liste_attente', $this->decision($attente));
        $this->assertDatabaseCount('remplacement', 0);
    }

    public function test_apres_publication_indiscernable_d_un_non_retenu_ordinaire(): void
    {
        DB::table('campagne_filiere')
            ->where('campagne_id', $this->campagne->id)
            ->where('filiere_id', $this->idFiliere('cuisine'))
            ->update(['quota' => 1]);

        $elimine = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        // Jamais classé (entretien non validé) -> non_retenu générique, sans ligne de décision.
        $ordinaire = $this->candidatureEvaluee(
            $this->campagne, $this->evaluateur, 'cuisine', 30.0, 15.0,
            ['entretien_statut' => 'planifie'],
        );

        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/classement")->assertOk();
        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/publier")->assertOk();

        $this->actingAs($this->admin)->postJson($this->url($elimine), [
            'motif' => 'Dossier frauduleux.',
        ])->assertOk();

        $vuElimine = $this->actingAs($this->candidatUser($elimine))->getJson('/api/candidature');
        $vuOrdinaire = $this->actingAs($this->candidatUser($ordinaire))->getJson('/api/candidature');

        $vuElimine->assertOk()
            ->assertJsonPath('data.statut_public', 'decision_publiee')
            ->assertJsonPath('data.decision', 'non_retenu')
            ->assertJsonPath('data.motif_communicable', null);

        // Les deux réponses ne diffèrent que par l'identité du dossier.
        $normalise = static function (string $json): array {
            $data = json_decode($json, true)['data'];
            unset($data['id'], $data['numero_dossier'], $data['date_soumission'], $data['reponses'], $data['classement']);

            return $data;
        };
        $this->assertSame(
            $normalise($vuOrdinaire->getContent()),
            $normalise($vuElimine->getContent()),
        );
    }

    public function test_une_candidature_en_brouillon_ne_peut_pas_etre_eliminee_422(): void
    {
        $candidat = $this->creerCandidat('brouillon@casa-demo.ci');
        $id = $this->actingAs($candidat)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');
        $brouillon = Candidature::findOrFail($id);

        $this->actingAs($this->admin)->postJson($this->url($brouillon), [
            'motif' => 'Trop tôt.',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('critere_eliminatoire_declenche', [
            'candidature_id' => $brouillon->id,
            'origine' => 'decision_administrative',
        ]);
    }

    public function test_elimination_reservee_a_l_administrateur(): void
    {
        $candidature = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 55.0, 28.0);

        foreach ([$this->evaluateur, $this->candidatUser($candidature)] as $intrus) {
            $this->actingAs($intrus)->postJson($this->url($candidature), [
                'motif' => 'Tentative.',
            ])->assertForbidden();
        }

        $this->assertSame('eligible', $candidature->fresh()->statut_eligibilite_interne);
    }
}
