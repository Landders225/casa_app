<?php

namespace Tests\Feature\Admin;

use App\Models\Campagne;
use App\Models\MembreEquipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * L'acte de publication (Lot 5b) : garde-fous, irréversibilité, audit, isolation.
 */
class PublicationTest extends TestCase
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

    private function url(): string
    {
        return "/api/admin/campagnes/{$this->campagne->id}/publier";
    }

    private function calculer(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/classement")->assertOk();
    }

    public function test_publier_sans_classement_calcule_422(): void
    {
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);

        // Aucun POST /classement lancé -> aucune decision_candidature.
        $this->actingAs($this->admin)->postJson($this->url())->assertStatus(422);
        $this->assertDatabaseCount('publication', 0);
    }

    public function test_publier_ecrit_la_publication_et_l_audit(): void
    {
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->calculer();

        $this->actingAs($this->admin)->postJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.candidats_avec_decision', 1)
            ->assertJsonPath('data.publiee_par.prenom', 'Admin');

        $this->assertDatabaseHas('publication', [
            'campagne_id' => $this->campagne->id,
            'publiee_par' => $this->admin->membreEquipe->id,
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $this->admin->id,
            'action' => 'Publication des résultats',
            'module' => 'Résultats',
        ]);
    }

    public function test_publication_irreversible_republier_409(): void
    {
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->calculer();

        $this->actingAs($this->admin)->postJson($this->url())->assertOk();
        $this->actingAs($this->admin)->postJson($this->url())->assertStatus(409);

        $this->assertDatabaseCount('publication', 1);
    }

    public function test_le_classement_n_est_plus_recalculable_apres_publication(): void
    {
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->calculer();
        $this->actingAs($this->admin)->postJson($this->url())->assertOk();

        // Cohérence avec le garde-fou du Lot 5a.
        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/classement")
            ->assertStatus(409);
    }

    public function test_publier_admin_only(): void
    {
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->calculer();
        $candidat = $this->creerCandidat('cand@casa-demo.ci');

        foreach ([$this->evaluateur, $candidat] as $intrus) {
            $this->actingAs($intrus)->postJson($this->url())->assertStatus(403);
        }
        $this->assertDatabaseCount('publication', 0);
    }

    public function test_get_classement_expose_publiee_le_et_publiee_par_apres_publication_lot_8d2(): void
    {
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->calculer();

        // Avant publication : absents (pas de F5-proof à donner puisqu'il n'y a
        // encore rien à tracer).
        $this->actingAs($this->admin)->getJson("/api/admin/campagnes/{$this->campagne->id}/classement")
            ->assertOk()
            ->assertJsonPath('data.publie', false)
            ->assertJsonPath('data.publiee_le', null)
            ->assertJsonPath('data.publiee_par', null);

        $this->actingAs($this->admin)->postJson($this->url())->assertOk();

        // Après publication : un DEUXIÈME appel GET indépendant (simule un
        // rechargement de page, pas la réponse du POST elle-même) doit encore
        // exposer la traçabilité — c'est tout le point de l'ajout (Étape 1 Q3).
        $reponse = $this->actingAs($this->admin)->getJson("/api/admin/campagnes/{$this->campagne->id}/classement")
            ->assertOk()
            ->assertJsonPath('data.publie', true)
            ->assertJsonPath('data.publiee_par.prenom', 'Admin')
            ->assertJsonPath('data.publiee_par.nom', 'Test');
        $this->assertNotNull($reponse->json('data.publiee_le'));

        // Liste blanche : le NOM, jamais l'e-mail de l'auteur.
        $this->assertStringNotContainsString('admin@casa-demo.ci', $reponse->getContent());
        $this->assertArrayHasKey('prenom', $reponse->json('data.publiee_par'));
        $this->assertArrayHasKey('nom', $reponse->json('data.publiee_par'));
        $this->assertSame(['prenom', 'nom'], array_keys($reponse->json('data.publiee_par')));
    }

    public function test_publier_sans_membre_equipe_422(): void
    {
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->calculer();

        MembreEquipe::where('utilisateur_id', $this->admin->id)->delete();

        $this->actingAs($this->admin->fresh())->postJson($this->url())->assertStatus(422);
        $this->assertDatabaseCount('publication', 0);
    }
}
