<?php

namespace Tests\Feature\Candidat;

use App\Models\ExperienceProfessionnelle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExperienceTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    private User $user;
    private string $candidatureId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferentiels();
        $this->user = $this->creerCandidat();
        $this->candidatureId = $this->actingAs($this->user)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('restaurant-bar')])
            ->json('data.id');
    }

    public function test_crud_experiences(): void
    {
        $create = $this->actingAs($this->user)->postJson(
            "/api/candidatures/{$this->candidatureId}/experiences",
            ['domaine' => 'hotellerie', 'duree_categorie' => '6_12'],
        )->assertCreated()->assertJsonPath('data.domaine', 'hotellerie');

        $expId = $create->json('data.id');

        // Deuxième expérience (répétable).
        $this->actingAs($this->user)->postJson(
            "/api/candidatures/{$this->candidatureId}/experiences",
            ['domaine' => 'commerce', 'duree_categorie' => 'plus_12'],
        )->assertCreated();

        $this->assertCount(2, ExperienceProfessionnelle::where('candidature_id', $this->candidatureId)->get());

        // Modification.
        $this->actingAs($this->user)->patchJson(
            "/api/candidatures/{$this->candidatureId}/experiences/{$expId}",
            ['duree_categorie' => 'moins_6'],
        )->assertOk()->assertJsonPath('data.duree_categorie', 'moins_6');

        // Suppression.
        $this->actingAs($this->user)->deleteJson("/api/candidatures/{$this->candidatureId}/experiences/{$expId}")
            ->assertNoContent();

        $this->assertCount(1, ExperienceProfessionnelle::where('candidature_id', $this->candidatureId)->get());
    }

    public function test_experience_sans_justificatif_est_acceptee_en_brouillon(): void
    {
        $this->actingAs($this->user)->postJson(
            "/api/candidatures/{$this->candidatureId}/experiences",
            ['domaine' => 'hotellerie', 'duree_categorie' => 'moins_6'],
        )->assertCreated();

        $this->assertNull(
            ExperienceProfessionnelle::where('candidature_id', $this->candidatureId)->first()->piece_justificative_id,
        );
    }

    public function test_domaine_invalide_rejete(): void
    {
        $this->actingAs($this->user)->postJson(
            "/api/candidatures/{$this->candidatureId}/experiences",
            ['domaine' => 'banque', 'duree_categorie' => '6_12'],
        )->assertStatus(422)->assertJsonValidationErrors('domaine');
    }

    public function test_duree_manquante_en_creation_rejetee(): void
    {
        $this->actingAs($this->user)->postJson(
            "/api/candidatures/{$this->candidatureId}/experiences",
            ['domaine' => 'hotellerie'],
        )->assertStatus(422)->assertJsonValidationErrors('duree_categorie');
    }
}
