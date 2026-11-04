<?php

namespace Tests\Feature\Candidat;

use App\Models\Campagne;
use App\Models\Candidature;
use App\Models\Filiere;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Clôture de campagne : blocage EFFECTIF de tout dépôt candidat (ADR-44),
 * annule la tolérance « brouillon soumissible après clôture » d'ADR-42.
 *
 * Toutes les routes gardées partagent la MÊME campagne RATTACHÉE à la
 * candidature (`Candidature::campagneFermee()`), jamais « une campagne
 * ouverte » au sens large — un cas d'échec par route gardée, un cas
 * « reste accessible » par route non gardée, puis non-régression complète
 * après `casa:rouvrir-campagne` (ADR-43).
 */
class ClotureCampagneBlocageTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    private User $user;

    private Candidature $candidature;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->user = $this->creerCandidat();
        $id = $this->actingAs($this->user)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');
        $this->candidature = Candidature::findOrFail($id);
    }

    private function cloturerCampagne(): void
    {
        Campagne::query()->update(['statut' => 'cloturee']);
    }

    // --- Routes gardées : 409, code `campagne_fermee`, aucune écriture ---

    public function test_soumission_bloquee_quand_la_campagne_rattachee_est_cloturee(): void
    {
        $this->rendreCandidatureComplete($this->candidature);
        $this->cloturerCampagne();

        $this->actingAs($this->user)
            ->postJson("/api/candidatures/{$this->candidature->id}/soumettre")
            ->assertStatus(409)
            ->assertJsonPath('message', "Les candidatures sont closes. Votre dossier n'a pas été soumis.")
            ->assertJsonPath('code', 'campagne_fermee');

        $this->assertSame('brouillon', $this->candidature->fresh()->statut_interne);
    }

    public function test_edition_de_brouillon_bloquee_sur_toutes_les_routes_garanties_par_le_meme_middleware(): void
    {
        $this->cloturerCampagne();
        $id = $this->candidature->id;

        $cas = [
            fn () => $this->actingAs($this->user)->patchJson("/api/candidatures/{$id}/reponses", ['sc01_scolarise_actuellement' => 'oui']),
            fn () => $this->actingAs($this->user)->postJson("/api/candidatures/{$id}/confirmer-filiere"),
            fn () => $this->actingAs($this->user)->postJson("/api/candidatures/{$id}/experiences", ['domaine' => 'commerce', 'duree_categorie' => 'moins_6']),
            fn () => $this->actingAs($this->user)->putJson("/api/candidatures/{$id}/classement", ['ordre' => Filiere::pluck('id')->all()]),
            fn () => $this->actingAs($this->user)->post("/api/candidatures/{$id}/pieces/cni", ['fichier' => $this->fichierPdf()]),
        ];

        foreach ($cas as $appel) {
            $appel()
                ->assertStatus(409)
                ->assertJsonPath('code', 'campagne_fermee')
                ->assertJsonPath('message', "Les candidatures sont closes : vos modifications n'ont pas pu être enregistrées.");
        }

        // Toujours en brouillon : aucune écriture n'a pu passer malgré les tentatives.
        $this->assertSame('brouillon', $this->candidature->fresh()->statut_interne);
    }

    public function test_creation_de_candidature_bloquee_porte_desormais_le_code_dedie(): void
    {
        $this->cloturerCampagne();
        $autre = $this->creerCandidat();

        $this->actingAs($autre)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->assertStatus(409)
            ->assertJsonPath('code', 'campagne_fermee');
    }

    // --- Routes NON gardées : restent accessibles malgré la clôture ---

    public function test_consultation_et_profil_restent_accessibles_apres_cloture(): void
    {
        $this->cloturerCampagne();

        $this->actingAs($this->user)->getJson('/api/candidature')->assertOk();
        $this->actingAs($this->user)->getJson("/api/candidatures/{$this->candidature->id}")->assertOk();
        $this->actingAs($this->user)->getJson("/api/candidatures/{$this->candidature->id}/pieces")->assertOk();
        $this->actingAs($this->user)->getJson('/api/candidat/profil')->assertOk();
        $this->actingAs($this->user)
            ->patchJson('/api/candidat/profil', ['telephone' => '0101010101'])
            ->assertOk();
    }

    // --- Non-régression : tout redevient possible après réouverture exceptionnelle ---

    public function test_tout_redevient_possible_apres_casa_rouvrir_campagne(): void
    {
        $this->rendreCandidatureComplete($this->candidature);
        $this->cloturerCampagne();

        $this->actingAs($this->user)
            ->postJson("/api/candidatures/{$this->candidature->id}/soumettre")
            ->assertStatus(409);

        $campagne = Campagne::query()->firstOrFail();
        $admin = User::factory()->create(['role' => 'administrateur']);

        $this->artisan('casa:rouvrir-campagne', [
            'campagne' => $campagne->id,
            '--nouvelle-cloture' => now()->addMonth()->format('Y-m-d'),
            '--raison' => 'Test de non-régression',
            '--operateur' => $admin->email,
        ])->expectsConfirmation('Confirmer la réouverture exceptionnelle ?', 'yes')
            ->assertSuccessful();

        $this->actingAs($this->user)
            ->postJson("/api/candidatures/{$this->candidature->id}/soumettre")
            ->assertOk();

        $this->assertSame('soumis', $this->candidature->fresh()->statut_interne);
    }
}
