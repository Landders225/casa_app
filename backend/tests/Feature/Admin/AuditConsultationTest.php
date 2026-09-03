<?php

namespace Tests\Feature\Admin;

use App\Models\Campagne;
use App\Models\Filiere;
use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;
use Tests\TestCase;

/**
 * Consultation du journal d'audit (Lot 6a) — administrateur strict ABSOLU,
 * lecture seule, filtres bornés.
 */
class AuditConsultationTest extends TestCase
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

        // Quelques actions tracées, via les vrais endpoints.
        $filiere = Filiere::where('code', 'cuisine')->firstOrFail();
        $this->actingAs($this->admin)->patchJson("/api/admin/filieres/{$filiere->id}", ['actif' => false])->assertOk();
        $this->actingAs($this->admin)->patchJson("/api/admin/filieres/{$filiere->id}", ['actif' => true])->assertOk();
        $campagne = Campagne::where('statut', 'ouverte')->firstOrFail();
        $this->actingAs($this->admin)->patchJson("/api/admin/campagnes/{$campagne->id}", ['statut' => 'cloturee'])->assertOk();

        // Une ligne 🔴 typique (motif interne + valeurs de score) posée directement.
        JournalAudit::create([
            'auteur_id' => $this->admin->id, 'role' => 'administrateur',
            'action' => "Validation d'évaluation", 'module' => 'Évaluation', 'objet' => 'CASA-2026-000001',
            'ancienne_valeur' => null, 'nouvelle_valeur' => '64.2/65',
            'motif' => 'SECRET INTERNE — score détaillé', 'resultat' => 'Succès',
        ]);
    }

    public function test_admin_consulte_le_journal_avec_les_champs_sensibles(): void
    {
        $this->actingAs($this->admin)->getJson('/api/admin/audit')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'horodatage', 'auteur' => ['email', 'role', 'nom'],
                'action', 'module', 'objet', 'ancienne_valeur', 'nouvelle_valeur', 'motif', 'resultat']]])
            ->assertJsonFragment(['nouvelle_valeur' => '64.2/65'])            // 🔴 visible de l'admin
            ->assertJsonFragment(['motif' => 'SECRET INTERNE — score détaillé']);
    }

    public function test_filtre_par_module(): void
    {
        $reponse = $this->actingAs($this->admin)->getJson('/api/admin/audit?module=Filières')->assertOk();

        $modules = collect($reponse->json('data'))->pluck('module')->unique();
        $this->assertSame(['Filières'], $modules->all());
        $this->assertCount(2, $reponse->json('data')); // activation + désactivation
    }

    public function test_filtre_par_action_et_par_auteur(): void
    {
        $this->actingAs($this->admin)->getJson('/api/admin/audit?action=Clôture de campagne')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.module', 'Campagnes');

        $this->actingAs($this->admin)->getJson('/api/admin/audit?auteur=admin@casa-demo.ci')
            ->assertOk()
            ->assertJsonPath('data.0.auteur.email', 'admin@casa-demo.ci');

        $this->actingAs($this->admin)->getJson('/api/admin/audit?auteur=inconnu@casa-demo.ci')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_recherche_bornee_a_action_et_objet_jamais_le_contenu_sensible(): void
    {
        // « SECRET » n'apparaît que dans `motif` (🔴) — la recherche ne doit pas le trouver.
        $this->actingAs($this->admin)->getJson('/api/admin/audit?recherche=SECRET')
            ->assertOk()->assertJsonCount(0, 'data');

        // « 64.2 » n'apparaît que dans `nouvelle_valeur` (🔴) — idem.
        $this->actingAs($this->admin)->getJson('/api/admin/audit?recherche=64.2')
            ->assertOk()->assertJsonCount(0, 'data');

        // En revanche, une recherche sur l'action fonctionne (« Clôture de campagne »).
        $this->actingAs($this->admin)->getJson('/api/admin/audit?recherche=campagne')
            ->assertOk()->assertJsonCount(1, 'data');

        // ...et sur l'objet (numéro de dossier).
        $this->actingAs($this->admin)->getJson('/api/admin/audit?recherche=CASA-2026-000001')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_journal_admin_only_403_pour_evaluateur_et_candidat(): void
    {
        foreach ([$this->creerEvaluateur('e@casa-demo.ci'), $this->creerCandidat('c@casa-demo.ci')] as $intrus) {
            $this->actingAs($intrus)->getJson('/api/admin/audit')->assertStatus(403);
        }
    }

    public function test_le_journal_est_en_lecture_seule(): void
    {
        // Aucune route d'écriture n'existe (le path /api/admin/audit n'accepte que GET).
        $this->actingAs($this->admin)->postJson('/api/admin/audit', [])->assertStatus(405);
        $this->actingAs($this->admin)->putJson('/api/admin/audit', [])->assertStatus(405);
        $this->actingAs($this->admin)->deleteJson('/api/admin/audit')->assertStatus(405);
    }
}
