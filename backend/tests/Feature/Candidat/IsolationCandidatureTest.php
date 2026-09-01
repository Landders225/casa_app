<?php

namespace Tests\Feature\Candidat;

use App\Models\Candidature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un candidat A ne peut NI lire NI modifier la candidature (ou une expérience)
 * d'un candidat B. Réponse attendue : 404 (pas 403 — pas d'énumération d'id).
 */
class IsolationCandidatureTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    private User $a;
    private User $b;
    private string $candidatureB;
    private string $experienceB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferentiels();

        $this->a = $this->creerCandidat('a@casa-demo.ci');
        $this->b = $this->creerCandidat('b@casa-demo.ci');

        $this->candidatureB = $this->actingAs($this->b)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');

        $this->experienceB = $this->actingAs($this->b)->postJson(
            "/api/candidatures/{$this->candidatureB}/experiences",
            ['domaine' => 'hotellerie', 'duree_categorie' => '6_12'],
        )->json('data.id');
    }

    public function test_A_ne_peut_pas_lire_la_candidature_de_B(): void
    {
        $this->actingAs($this->a)->getJson("/api/candidatures/{$this->candidatureB}")
            ->assertStatus(404);
    }

    public function test_A_ne_peut_pas_modifier_les_reponses_de_B(): void
    {
        $this->actingAs($this->a)->patchJson(
            "/api/candidatures/{$this->candidatureB}/reponses",
            ['sc01_scolarise_actuellement' => 'non'],
        )->assertStatus(404);

        $this->assertNull(Candidature::find($this->candidatureB)->reponseFormulaire->sc01_scolarise_actuellement);
    }

    public function test_A_ne_peut_pas_confirmer_la_filiere_de_B(): void
    {
        $this->actingAs($this->a)->postJson("/api/candidatures/{$this->candidatureB}/confirmer-filiere")
            ->assertStatus(404);

        $this->assertFalse(Candidature::find($this->candidatureB)->cqp_confirme);
    }

    public function test_A_ne_peut_pas_supprimer_une_experience_de_B(): void
    {
        $this->actingAs($this->a)->deleteJson(
            "/api/candidatures/{$this->candidatureB}/experiences/{$this->experienceB}",
        )->assertStatus(404);

        $this->assertDatabaseHas('experience_professionnelle', ['id' => $this->experienceB]);
    }

    public function test_A_ne_peut_pas_modifier_le_classement_de_B(): void
    {
        $ordre = \App\Models\Filiere::orderBy('code')->pluck('id')->all();

        $this->actingAs($this->a)->putJson(
            "/api/candidatures/{$this->candidatureB}/classement",
            ['ordre' => $ordre],
        )->assertStatus(404);
    }

    public function test_get_candidature_courante_de_A_ne_renvoie_jamais_celle_de_B(): void
    {
        // A n'a pas de candidature -> 404, jamais celle de B.
        $this->actingAs($this->a)->getJson('/api/candidature')->assertStatus(404);
    }

    public function test_experience_d_une_autre_candidature_via_sa_propre_candidature_404(): void
    {
        $candidatureA = $this->actingAs($this->a)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('buanderie')])
            ->json('data.id');

        // A tente d'atteindre l'expérience de B en passant par SA candidature.
        $this->actingAs($this->a)->patchJson(
            "/api/candidatures/{$candidatureA}/experiences/{$this->experienceB}",
            ['duree_categorie' => 'moins_6'],
        )->assertStatus(404);
    }
}
