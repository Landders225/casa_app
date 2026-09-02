<?php

namespace Tests\Feature\Admin;

use App\Models\Campagne;
use App\Models\DecisionCandidature;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Calcul + persistance des décisions internes (Lot 5a), exclusions, idempotence,
 * garde publication, isolation admin-only.
 */
class ClassementDecisionsTest extends TestCase
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

    private function urlCalcul(): string
    {
        return "/api/admin/campagnes/{$this->campagne->id}/classement";
    }

    public function test_le_calcul_persiste_les_decisions_et_ecrit_l_audit(): void
    {
        $c1 = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0); // 90.0
        $c2 = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 50.0, 20.0); // 70.0

        $this->actingAs($this->admin)->postJson($this->urlCalcul())
            ->assertOk()
            ->assertJsonPath('data.calcule', true)
            ->assertJsonPath('data.publie', false);

        $this->assertDatabaseHas('decision_candidature', ['candidature_id' => $c1->id, 'rang' => 1, 'decision' => 'retenu']);
        $this->assertDatabaseHas('decision_candidature', ['candidature_id' => $c2->id, 'rang' => 2, 'decision' => 'retenu']);

        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $this->admin->id,
            'action' => 'Calcul du classement',
            'module' => 'Classement',
        ]);
    }

    public function test_un_non_eligible_evalue_recoit_une_decision_non_retenu_explicite(): void
    {
        // D-5a-4 : décision explicite + motif_interne 🔴, rang NULL.
        $ne = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 55.0, 25.0, [
            'eligibilite' => 'non_eligible',
        ]);

        $this->actingAs($this->admin)->postJson($this->urlCalcul())->assertOk();

        $decision = DecisionCandidature::findOrFail($ne->id);
        $this->assertSame('non_retenu', $decision->decision);
        $this->assertNull($decision->rang, 'un non-éligible n\'est pas classé');
        $this->assertSame('non éligible', $decision->motif_interne);
    }

    public function test_un_dossier_sans_entretien_valide_est_exclu(): void
    {
        $sansEntretien = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 0.0, [
            'entretien_statut' => 'realise',
        ]);
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 50.0, 20.0);

        $this->actingAs($this->admin)->postJson($this->urlCalcul())->assertOk();

        $this->assertDatabaseMissing('decision_candidature', ['candidature_id' => $sansEntretien->id]);
    }

    public function test_le_calcul_est_idempotent_et_preserve_les_motifs(): void
    {
        $premier = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $second = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 20.0, 10.0);

        $this->actingAs($this->admin)->postJson($this->urlCalcul())->assertOk();

        // L'admin renseigne les motifs sur une ligne.
        $this->actingAs($this->admin)->putJson("/api/admin/candidatures/{$second->id}/decision/motifs", [
            'motif_interne' => 'Profil intéressant mais quota atteint.',
            'motif_communicable' => 'Nous reviendrons vers vous en cas de désistement.',
        ])->assertOk();

        $rangAvant = DecisionCandidature::findOrFail($premier->id)->rang;
        $decisionAvant = DecisionCandidature::findOrFail($second->id)->decision;

        // Recalcul : rangs/décisions identiques, motifs préservés (D-5a-3).
        $this->actingAs($this->admin)->postJson($this->urlCalcul())->assertOk();

        $decision = DecisionCandidature::findOrFail($second->id);
        $this->assertSame('Profil intéressant mais quota atteint.', $decision->motif_interne);
        $this->assertSame('Nous reviendrons vers vous en cas de désistement.', $decision->motif_communicable);
        $this->assertSame($decisionAvant, $decision->decision);
        $this->assertSame($rangAvant, DecisionCandidature::findOrFail($premier->id)->rang);
    }

    public function test_une_decision_devenue_non_classable_est_supprimee_au_recalcul(): void
    {
        $c = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->actingAs($this->admin)->postJson($this->urlCalcul())->assertOk();
        $this->assertDatabaseHas('decision_candidature', ['candidature_id' => $c->id]);

        // L'entretien est "dé-validé" (correction hypothétique) → plus classable.
        $c->entretien->forceFill(['statut' => 'realise'])->save();

        $this->actingAs($this->admin)->postJson($this->urlCalcul())->assertOk();
        $this->assertDatabaseMissing('decision_candidature', ['candidature_id' => $c->id]);
    }

    public function test_le_calcul_est_refuse_si_la_campagne_est_publiee_409(): void
    {
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->actingAs($this->admin)->postJson($this->urlCalcul())->assertOk();

        Publication::create([
            'campagne_id' => $this->campagne->id,
            'publiee_le' => now(),
            'publiee_par' => $this->admin->membreEquipe->id,
        ]);

        $this->actingAs($this->admin)->postJson($this->urlCalcul())->assertStatus(409);
    }

    public function test_motifs_impossible_sans_decision_prealable_404(): void
    {
        $c = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);

        $this->actingAs($this->admin)
            ->putJson("/api/admin/candidatures/{$c->id}/decision/motifs", ['motif_interne' => 'x'])
            ->assertStatus(404);
    }

    public function test_GET_classement_lit_les_decisions_persistees(): void
    {
        $c1 = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'restaurant-bar', 55.0, 25.0);
        $this->actingAs($this->admin)->postJson($this->urlCalcul())->assertOk();

        $this->actingAs($this->admin)->getJson($this->urlCalcul())
            ->assertOk()
            ->assertJsonPath('data.calcule', true)
            ->assertJsonPath('data.liste_attente_taille', 8)
            ->assertJsonCount(5, 'data.filieres'); // les 5 filières de campagne_filiere

        // Filtre par filière.
        $this->actingAs($this->admin)->getJson($this->urlCalcul().'?filiere=cuisine')
            ->assertOk()
            ->assertJsonCount(1, 'data.filieres')
            ->assertJsonPath('data.filieres.0.filiere.code', 'cuisine')
            ->assertJsonPath('data.filieres.0.lignes.0.candidature_id', $c1->id)
            ->assertJsonPath('data.filieres.0.lignes.0.rang', 1)
            ->assertJsonPath('data.filieres.0.lignes.0.decision', 'retenu')
            ->assertJsonPath('data.filieres.0.lignes.0.score_final', '90.0');
    }

    public function test_isolation_admin_only(): void
    {
        $c = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $candidat = $this->creerCandidat('cand@casa-demo.ci');

        foreach ([$this->evaluateur, $candidat] as $intrus) {
            $this->actingAs($intrus)->postJson($this->urlCalcul())->assertStatus(403);
            $this->actingAs($intrus)->getJson($this->urlCalcul())->assertStatus(403);
            $this->actingAs($intrus)
                ->putJson("/api/admin/candidatures/{$c->id}/decision/motifs", ['motif_interne' => 'x'])
                ->assertStatus(403);
        }
    }
}
