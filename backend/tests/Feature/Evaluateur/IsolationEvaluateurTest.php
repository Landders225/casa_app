<?php

namespace Tests\Feature\Evaluateur;

use App\Models\Candidature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Un évaluateur n'accède qu'à SES dossiers affectés. Un admin voit tout.
 * Refus = 404 (jamais 403), cohérence zéro-fuite avec le reste du projet.
 */
class IsolationEvaluateurTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private User $evalA;
    private User $evalB;
    private Candidature $dossierDeB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->evalA = $this->creerEvaluateur('a@casa-demo.ci');
        $this->evalB = $this->creerEvaluateur('b@casa-demo.ci');
        $this->dossierDeB = $this->candidatureAffectee($this->creerCandidat('c@casa-demo.ci'), $this->evalB);
    }

    public function test_A_ne_voit_pas_la_fiche_du_dossier_de_B(): void
    {
        $this->actingAs($this->evalA)->getJson("/api/evaluateur/candidatures/{$this->dossierDeB->id}")
            ->assertStatus(404);
    }

    public function test_A_ne_verifie_pas_le_dossier_de_B(): void
    {
        $this->actingAs($this->evalA)
            ->putJson("/api/evaluateur/candidatures/{$this->dossierDeB->id}/verification", ['diplome_verifie' => 'bac'])
            ->assertStatus(404);

        $this->assertDatabaseMissing('verification_dossier', ['candidature_id' => $this->dossierDeB->id]);
    }

    public function test_admin_voit_tous_les_dossiers_meme_non_affectes(): void
    {
        $admin = $this->creerAdmin('admin@casa-demo.ci');

        $this->actingAs($admin)->getJson("/api/evaluateur/candidatures/{$this->dossierDeB->id}")
            ->assertOk()
            ->assertJsonPath('data.numero_dossier', $this->dossierDeB->numero_dossier);

        $this->actingAs($admin)->getJson('/api/evaluateur/candidatures')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_dossier_inexistant_404(): void
    {
        $this->actingAs($this->evalA)
            ->getJson('/api/evaluateur/candidatures/'.\Illuminate\Support\Str::uuid())
            ->assertStatus(404);
    }
}
