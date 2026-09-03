<?php

namespace Tests\Feature\Admin;

use App\Models\Campagne;
use App\Models\Filiere;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;
use Tests\TestCase;

/**
 * Gestion des filières et des campagnes (Lot 6a) + impacts de cohérence sur le
 * `POST /api/candidatures` du Lot 3a.
 */
class GestionFiliereCampagneTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->admin = $this->creerAdmin('admin@casa-demo.ci');
    }

    private function creerCandidature(string $filiere = 'cuisine'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->creerCandidat())
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere($filiere)]);
    }

    // --- Filières ---

    public function test_desactiver_une_filiere_refuse_les_nouvelles_candidatures_422(): void
    {
        $filiere = Filiere::where('code', 'cuisine')->firstOrFail();

        $this->creerCandidature('cuisine')->assertStatus(201); // OK avant

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/filieres/{$filiere->id}", ['actif' => false])
            ->assertOk()
            ->assertJsonPath('data.actif', false);

        $this->creerCandidature('cuisine')->assertStatus(422); // refusée après

        $this->assertDatabaseHas('journal_audit', [
            'action' => 'Désactivation de filière',
            'module' => 'Filières',
            'objet' => $filiere->nom,
            'ancienne_valeur' => 'Active',
            'nouvelle_valeur' => 'Inactive',
        ]);
    }

    public function test_reactiver_une_filiere_rouvre_les_candidatures(): void
    {
        $filiere = Filiere::where('code', 'buanderie')->firstOrFail();

        $this->actingAs($this->admin)->patchJson("/api/admin/filieres/{$filiere->id}", ['actif' => false])->assertOk();
        $this->creerCandidature('buanderie')->assertStatus(422);

        $this->actingAs($this->admin)->patchJson("/api/admin/filieres/{$filiere->id}", ['actif' => true])->assertOk();
        $this->creerCandidature('buanderie')->assertStatus(201);
    }

    public function test_changement_de_statut_filiere_idempotent_sans_audit(): void
    {
        $filiere = Filiere::where('code', 'cuisine')->firstOrFail(); // déjà active

        $this->actingAs($this->admin)->patchJson("/api/admin/filieres/{$filiere->id}", ['actif' => true])->assertOk();

        $this->assertSame(0, \App\Models\JournalAudit::where('module', 'Filières')->count());
    }

    public function test_gestion_filiere_admin_only(): void
    {
        $filiere = Filiere::where('code', 'cuisine')->firstOrFail();
        $evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');

        foreach ([$evaluateur, $this->creerCandidat('c@casa-demo.ci')] as $intrus) {
            $this->actingAs($intrus)
                ->patchJson("/api/admin/filieres/{$filiere->id}", ['actif' => false])
                ->assertStatus(403);
        }
    }

    // --- Campagnes ---

    public function test_cloturer_la_campagne_refuse_les_nouvelles_candidatures_409(): void
    {
        $campagne = Campagne::where('statut', 'ouverte')->firstOrFail();

        $this->creerCandidature()->assertStatus(201); // OK avant

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/campagnes/{$campagne->id}", ['statut' => 'cloturee'])
            ->assertOk()
            ->assertJsonPath('data.statut', 'cloturee');

        $this->creerCandidature()->assertStatus(409); // « Aucune campagne ouverte »

        $this->assertDatabaseHas('journal_audit', [
            'action' => 'Clôture de campagne',
            'module' => 'Campagnes',
            'ancienne_valeur' => 'Ouverte',
            'nouvelle_valeur' => 'Clôturée',
        ]);
    }

    public function test_le_candidat_consulte_toujours_son_dossier_apres_cloture_de_la_campagne(): void
    {
        $candidat = $this->creerCandidat('cand@casa-demo.ci');
        $this->actingAs($candidat)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->assertStatus(201);

        $campagne = Campagne::where('statut', 'ouverte')->firstOrFail();
        $this->actingAs($this->admin)
            ->patchJson("/api/admin/campagnes/{$campagne->id}", ['statut' => 'cloturee'])->assertOk();

        // La campagne est fermée, mais le candidat ACCÈDE toujours à SA candidature
        // (200, pas 404) — indispensable pour la vue résultat après publication (5b).
        $this->actingAs($candidat)->getJson('/api/candidature')
            ->assertOk()
            ->assertJsonPath('data.statut_public', 'brouillon');
    }

    public function test_ouvrir_une_campagne_refuse_si_une_autre_est_deja_ouverte_409(): void
    {
        // La campagne seedée est déjà ouverte. On en crée une en brouillon.
        $brouillon = Campagne::create([
            'nom' => 'Cohorte 2 — 2027', 'statut' => 'brouillon',
            'date_ouverture' => '2027-01-01', 'date_cloture' => '2027-02-01', 'places_totales' => 120,
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/campagnes/{$brouillon->id}", ['statut' => 'ouverte'])
            ->assertStatus(409);

        $this->assertSame('brouillon', $brouillon->fresh()->statut);
    }

    public function test_ouvrir_une_campagne_brouillon_quand_aucune_autre_ouverte(): void
    {
        Campagne::where('statut', 'ouverte')->update(['statut' => 'cloturee']);
        $brouillon = Campagne::create([
            'nom' => 'Cohorte 2 — 2027', 'statut' => 'brouillon',
            'date_ouverture' => '2027-01-01', 'date_cloture' => '2027-02-01', 'places_totales' => 120,
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/campagnes/{$brouillon->id}", ['statut' => 'ouverte'])
            ->assertOk()
            ->assertJsonPath('data.statut', 'ouverte');

        $this->assertDatabaseHas('journal_audit', [
            'action' => 'Ouverture de campagne',
            'ancienne_valeur' => 'Brouillon',
            'nouvelle_valeur' => 'Ouverte',
        ]);
    }

    public function test_transition_de_statut_non_autorisee_422(): void
    {
        $campagne = Campagne::where('statut', 'ouverte')->firstOrFail();

        // ouverte -> ouverte : non autorisée
        $this->actingAs($this->admin)
            ->patchJson("/api/admin/campagnes/{$campagne->id}", ['statut' => 'ouverte'])
            ->assertStatus(422);

        // cloturee -> ouverte : terminal
        $campagne->update(['statut' => 'cloturee']);
        $this->actingAs($this->admin)
            ->patchJson("/api/admin/campagnes/{$campagne->id}", ['statut' => 'ouverte'])
            ->assertStatus(422);
    }
}
