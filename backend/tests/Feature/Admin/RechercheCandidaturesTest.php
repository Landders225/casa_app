<?php

namespace Tests\Feature\Admin;

use App\Models\Campagne;
use App\Models\Candidature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Recherche + filtres + tri de `POST /api/admin/candidatures` (lot
 * recherche-admin, ADR-39). Jetons fictifs uniquement — aucune donnée réelle
 * de candidat, nulle part.
 */
class RechercheCandidaturesTest extends TestCase
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

    public function test_recherche_par_numero_dossier_partiel_jamais_en_prefixe(): void
    {
        $c1 = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 50.0, 20.0);

        // c1 = CASA-2026-000001, c2 = CASA-2026-000002 : seul "01" (à la FIN du
        // numéro, jamais au début) distingue les deux — une recherche en
        // PRÉFIXE échouerait systématiquement (le numéro commence par "CASA").
        $this->actingAs($this->admin)->postJson('/api/admin/candidatures', ['recherche' => '01'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_dossier', $c1->numero_dossier);
    }

    public function test_recherche_par_nom_insensible_a_la_casse_et_aux_accents(): void
    {
        $c = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0, [
            'prenom' => 'Temoignage', 'nom' => 'Kouamé',
        ]);
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 50.0, 20.0, [
            'prenom' => 'Autre', 'nom' => 'Personne',
        ]);

        $this->actingAs($this->admin)->postJson('/api/admin/candidatures', ['recherche' => 'KOUAME'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_dossier', $c->numero_dossier);
    }

    public function test_recherche_ordre_inverse_nom_prenom(): void
    {
        $c = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0, [
            'prenom' => 'Awa', 'nom' => 'Kone',
        ]);

        $this->actingAs($this->admin)->postJson('/api/admin/candidatures', ['recherche' => 'Kone Awa'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_dossier', $c->numero_dossier);
    }

    public function test_recherche_insensible_a_lapostrophe(): void
    {
        $c = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0, [
            'prenom' => 'Jean', 'nom' => "N'Guessan",
        ]);

        $this->actingAs($this->admin)->postJson('/api/admin/candidatures', ['recherche' => 'NGUESSAN'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_dossier', $c->numero_dossier);
    }

    public function test_recherche_insensible_au_tiret(): void
    {
        $c = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0, [
            'prenom' => 'Marie', 'nom' => 'Traore-Diomande',
        ]);

        $this->actingAs($this->admin)->postJson('/api/admin/candidatures', ['recherche' => 'traorediomande'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_dossier', $c->numero_dossier);
    }

    public function test_pourcentage_litteral_ne_renvoie_pas_tout(): void
    {
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 50.0, 20.0);

        $this->actingAs($this->admin)->postJson('/api/admin/candidatures', ['recherche' => '%'])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_recherche_sans_resultat(): void
    {
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);

        $this->actingAs($this->admin)->postJson('/api/admin/candidatures', ['recherche' => 'Inexistant'])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_filtres_combines_recherche_et_eligibilite(): void
    {
        $eligible = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0, [
            'prenom' => 'Fatou', 'nom' => 'Diabate', 'eligibilite' => 'eligible',
        ]);
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0, [
            'prenom' => 'Fatou', 'nom' => 'Diabate', 'eligibilite' => 'non_eligible',
        ]);

        $this->actingAs($this->admin)->postJson('/api/admin/candidatures', [
            'recherche' => 'Diabate', 'eligibilite' => 'eligible',
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_dossier', $eligible->numero_dossier);
    }

    public function test_filtre_decision_sans_decision(): void
    {
        $sansDecision = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $avecDecision = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 50.0, 20.0);
        $avecDecision->decisionCandidature()->create(['decision' => 'retenu', 'rang' => 1]);

        $this->actingAs($this->admin)->postJson('/api/admin/candidatures', ['decision' => 'sans_decision'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_dossier', $sansDecision->numero_dossier);

        $this->actingAs($this->admin)->postJson('/api/admin/candidatures', ['decision' => 'retenu'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_dossier', $avecDecision->numero_dossier);
    }

    public function test_tri_avec_ex_aequo_aucun_doublon(): void
    {
        // Même score (ex æquo) pour plusieurs candidatures : le second critère
        // `candidature.id` doit garantir un ordre total stable, sans doublon
        // ni absence dans la page retournée.
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0)->id;
        }

        $page = $this->actingAs($this->admin)
            ->postJson('/api/admin/candidatures', ['tri_colonne' => 'score_dossier', 'tri_direction' => 'desc'])
            ->assertOk()
            ->json('data');

        $idsVus = array_column($page, 'id');
        $this->assertCount(5, $idsVus);
        $this->assertSame(count($idsVus), count(array_unique($idsVus)), 'aucun doublon dans la page');
        sort($ids);
        sort($idsVus);
        $this->assertSame($ids, $idsVus);
    }

    public function test_total_par_defaut_egale_candidature_soumises(): void
    {
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'restaurant-bar', 50.0, 20.0);
        $brouillon = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 0.0, 0.0, [
            'entretien_statut' => 'realise',
        ]);
        $brouillon->forceFill(['statut_interne' => 'brouillon'])->saveQuietly();

        $reponse = $this->actingAs($this->admin)->postJson('/api/admin/candidatures')->assertOk();

        $this->assertSame(Candidature::soumises()->count(), $reponse->json('meta.total'));
        $this->assertSame(2, $reponse->json('meta.total'));
    }

    public function test_recherche_admin_only(): void
    {
        $this->actingAs($this->evaluateur)->postJson('/api/admin/candidatures', ['recherche' => 'x'])->assertStatus(403);
    }
}
