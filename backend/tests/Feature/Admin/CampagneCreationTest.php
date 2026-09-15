<?php

namespace Tests\Feature\Admin;

use App\Models\Campagne;
use App\Models\Filiere;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;
use Tests\TestCase;

/**
 * Création d'une campagne par l'écran (Lot 17, D-6a-2, Étape 1 Q1) —
 * administrateur strict.
 *
 * Le point central : une campagne créée est TOUJOURS `brouillon`, jamais
 * `ouverte` d'emblée — le garde-fou « une seule campagne ouverte » (Lot 6a)
 * ne peut donc jamais être violé par une création, même quand une autre
 * campagne est déjà ouverte.
 */
class CampagneCreationTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferentiels(); // Cohorte 1 — 2026, déjà OUVERTE, 5 filières actives
        $this->admin = $this->creerAdmin('admin@casa-demo.ci');
    }

    private function payload(array $overrides = []): array
    {
        $filieres = Filiere::query()->where('actif', true)->limit(2)->get();

        return array_merge([
            'nom' => 'Cohorte 2 — 2027',
            'date_ouverture' => '2027-01-01',
            'date_cloture' => '2027-02-28',
            'filieres' => $filieres->map(fn ($f) => ['filiere_id' => $f->id, 'quota' => 10])->all(),
        ], $overrides);
    }

    public function test_creation_reussit_toujours_en_brouillon_meme_si_une_autre_campagne_est_ouverte(): void
    {
        $this->assertTrue(Campagne::ouverte()->exists(), 'précondition : une campagne est déjà ouverte');

        $reponse = $this->actingAs($this->admin)->postJson('/api/admin/campagnes', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.statut', 'brouillon');

        $id = $reponse->json('data.id');
        $this->assertDatabaseHas('campagne', ['id' => $id, 'statut' => 'brouillon']);

        // Le garde-fou « une seule ouverte » n'a pas été effleuré : toujours
        // exactement une campagne ouverte (celle d'avant, inchangée).
        $this->assertSame(1, Campagne::where('statut', 'ouverte')->count());
    }

    public function test_le_statut_envoye_par_le_client_est_ignore(): void
    {
        $reponse = $this->actingAs($this->admin)
            ->postJson('/api/admin/campagnes', $this->payload(['statut' => 'ouverte']))
            ->assertCreated();

        $this->assertSame('brouillon', $reponse->json('data.statut'));
    }

    public function test_places_totales_est_derive_de_la_somme_des_quotas(): void
    {
        $filieres = Filiere::query()->where('actif', true)->limit(3)->get();
        $payload = $this->payload([
            'filieres' => [
                ['filiere_id' => $filieres[0]->id, 'quota' => 15],
                ['filiere_id' => $filieres[1]->id, 'quota' => 20],
                ['filiere_id' => $filieres[2]->id, 'quota' => 5],
            ],
        ]);

        $reponse = $this->actingAs($this->admin)->postJson('/api/admin/campagnes', $payload)->assertCreated();

        $this->assertSame(40, $reponse->json('data.places_totales'));
        $this->assertCount(3, $reponse->json('data.filieres'));
    }

    public function test_au_moins_une_filiere_requise_422(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/campagnes', $this->payload(['filieres' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('filieres');
    }

    public function test_filiere_inactive_refusee_422(): void
    {
        $inactive = Filiere::query()->first();
        $inactive->update(['actif' => false]);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/campagnes', $this->payload([
                'filieres' => [['filiere_id' => $inactive->id, 'quota' => 10]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('filieres.0.filiere_id');
    }

    public function test_filiere_dupliquee_refusee_422(): void
    {
        $f = Filiere::query()->where('actif', true)->firstOrFail();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/campagnes', $this->payload([
                'filieres' => [
                    ['filiere_id' => $f->id, 'quota' => 10],
                    ['filiere_id' => $f->id, 'quota' => 5],
                ],
            ]))
            ->assertStatus(422);
    }

    public function test_quota_negatif_refuse_422(): void
    {
        $f = Filiere::query()->where('actif', true)->firstOrFail();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/campagnes', $this->payload([
                'filieres' => [['filiere_id' => $f->id, 'quota' => -1]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('filieres.0.quota');
    }

    public function test_date_cloture_avant_ouverture_refusee_422(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/campagnes', $this->payload([
                'date_ouverture' => '2027-02-01',
                'date_cloture' => '2027-01-01',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_cloture');
    }

    public function test_audit_ecrit_a_la_creation(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/campagnes', $this->payload())->assertCreated();

        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $this->admin->id,
            'action' => 'Création de campagne',
            'module' => 'Campagnes',
            'objet' => 'Cohorte 2 — 2027',
        ]);
    }

    public function test_creation_admin_only_403(): void
    {
        foreach ([$this->creerEvaluateur('e@casa-demo.ci'), $this->creerCandidat('c@casa-demo.ci')] as $intrus) {
            $this->actingAs($intrus)->postJson('/api/admin/campagnes', $this->payload())->assertStatus(403);
        }
    }
}
