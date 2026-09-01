<?php

namespace Tests\Feature\Candidat;

use App\Models\Filiere;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassementTest extends TestCase
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
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');
    }

    public function test_classement_prerempli_a_la_creation(): void
    {
        $classement = $this->actingAs($this->user)
            ->getJson("/api/candidatures/{$this->candidatureId}")
            ->json('data.classement');

        $this->assertCount(5, $classement);
        $this->assertSame(1, $classement[0]['rang']);
        $this->assertSame('cuisine', $classement[0]['filiere']['code']);
    }

    public function test_put_classement_reordonne(): void
    {
        $ordre = Filiere::orderBy('code')->pluck('id')->all(); // 5 filières

        $this->actingAs($this->user)->putJson(
            "/api/candidatures/{$this->candidatureId}/classement",
            ['ordre' => $ordre],
        )->assertOk();

        $classement = $this->actingAs($this->user)
            ->getJson("/api/candidatures/{$this->candidatureId}")
            ->json('data.classement');

        $this->assertSame($ordre[0], $classement[0]['filiere']['id']);
        $this->assertSame([1, 2, 3, 4, 5], array_column($classement, 'rang'));
    }

    public function test_classement_incomplet_rejete(): void
    {
        $quatre = Filiere::orderBy('code')->limit(4)->pluck('id')->all();

        $this->actingAs($this->user)->putJson(
            "/api/candidatures/{$this->candidatureId}/classement",
            ['ordre' => $quatre],
        )->assertStatus(422)->assertJsonValidationErrors('ordre');
    }

    public function test_classement_avec_filiere_inconnue_rejete(): void
    {
        $ordre = Filiere::orderBy('code')->limit(4)->pluck('id')->all();
        $ordre[] = (string) \Illuminate\Support\Str::uuid();

        $this->actingAs($this->user)->putJson(
            "/api/candidatures/{$this->candidatureId}/classement",
            ['ordre' => $ordre],
        )->assertStatus(422);
    }

    public function test_classement_avec_doublon_rejete(): void
    {
        $ordre = Filiere::orderBy('code')->pluck('id')->all();
        $ordre[4] = $ordre[0];

        $this->actingAs($this->user)->putJson(
            "/api/candidatures/{$this->candidatureId}/classement",
            ['ordre' => $ordre],
        )->assertStatus(422);
    }
}
