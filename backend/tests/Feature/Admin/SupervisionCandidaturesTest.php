<?php

namespace Tests\Feature\Admin;

use App\Models\Campagne;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `GET /api/admin/candidatures` (Lot 6a) — vue de supervision, 🔴 admin-only.
 */
class SupervisionCandidaturesTest extends TestCase
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

    public function test_l_admin_voit_la_zone_interne_de_chaque_candidature(): void
    {
        $c = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);

        $this->actingAs($this->admin)->getJson('/api/admin/candidatures')
            ->assertOk()
            ->assertJsonPath('data.0.numero_dossier', $c->numero_dossier)
            ->assertJsonPath('data.0.statut_interne', 'evalue')
            ->assertJsonPath('data.0.statut_eligibilite_interne', 'eligible')
            ->assertJsonPath('data.0.evaluateur.prenom', 'Eval')
            ->assertJsonPath('data.0.score_dossier', '60.0')
            ->assertJsonPath('data.0.score_entretien', '30.0');
    }

    public function test_filtres_statut_filiere_et_non_affecte(): void
    {
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'restaurant-bar', 50.0, 20.0);

        $this->actingAs($this->admin)->getJson('/api/admin/candidatures?filiere=cuisine')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.filiere.code', 'cuisine');

        $this->actingAs($this->admin)->getJson('/api/admin/candidatures?evaluateur=non_affecte')
            ->assertOk()->assertJsonCount(0, 'data'); // toutes affectées par le contexte
    }

    public function test_les_brouillons_sont_exclus_par_defaut(): void
    {
        $brouillon = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 0.0, 0.0, [
            'entretien_statut' => 'realise',
        ]);
        $brouillon->forceFill(['statut_interne' => 'brouillon'])->saveQuietly();

        $this->actingAs($this->admin)->getJson('/api/admin/candidatures')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->admin)->getJson('/api/admin/candidatures?inclure_brouillons=1')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_supervision_admin_only(): void
    {
        foreach ([$this->evaluateur, $this->creerCandidat('c@casa-demo.ci')] as $intrus) {
            $this->actingAs($intrus)->getJson('/api/admin/candidatures')->assertStatus(403);
        }
    }
}
