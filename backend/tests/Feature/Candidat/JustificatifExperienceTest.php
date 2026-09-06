<?php

namespace Tests\Feature\Candidat;

use App\Models\ExperienceProfessionnelle;
use App\Models\PieceJustificative;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JustificatifExperienceTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    private User $user;

    private string $candidatureId;

    private string $experienceId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->user = $this->creerCandidat();
        $this->candidatureId = $this->actingAs($this->user)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('restaurant-bar')])
            ->json('data.id');
        $this->experienceId = $this->actingAs($this->user)->postJson(
            "/api/candidatures/{$this->candidatureId}/experiences",
            ['domaine' => 'hotellerie', 'duree_categorie' => '6_12'],
        )->json('data.id');
    }

    private function url(string $suffixe = ''): string
    {
        return "/api/candidatures/{$this->candidatureId}/experiences/{$this->experienceId}/justificatif{$suffixe}";
    }

    public function test_depot_du_justificatif_d_experience(): void
    {
        $this->actingAs($this->user)->post($this->url(), ['fichier' => $this->fichierPdf('attestation.pdf')])
            ->assertCreated()
            ->assertJsonPath('data.rattachement', 'experience')
            ->assertJsonPath('data.type_document_code', null)
            ->assertJsonPath('data.experience_id', $this->experienceId);

        $exp = ExperienceProfessionnelle::find($this->experienceId);
        $this->assertNotNull($exp->piece_justificative_id);

        $piece = $exp->pieceJustificative;
        $this->assertSame('experience', $piece->rattachement);
        $this->assertNull($piece->candidature_id);        // CHECK d'exclusivité
        $this->assertNull($piece->type_document_code);
        Storage::disk('documents')->assertExists($piece->chemin_stockage);
    }

    public function test_remplacement_du_justificatif(): void
    {
        $this->actingAs($this->user)->post($this->url(), ['fichier' => $this->fichierPdf('v1.pdf')])->assertCreated();
        $ancienChemin = ExperienceProfessionnelle::find($this->experienceId)->pieceJustificative->chemin_stockage;

        $this->actingAs($this->user)->post($this->url(), ['fichier' => $this->fichierJpeg('v2.jpg')])
            ->assertOk()->assertJsonPath('data.type_mime', 'image/jpeg');

        // Une seule pièce d'expérience, l'ancien fichier supprimé.
        $this->assertSame(1, PieceJustificative::where('rattachement', 'experience')->count());
        Storage::disk('documents')->assertMissing($ancienChemin);
    }

    public function test_suppression_du_justificatif(): void
    {
        $this->actingAs($this->user)->post($this->url(), ['fichier' => $this->fichierPdf()])->assertCreated();
        $chemin = ExperienceProfessionnelle::find($this->experienceId)->pieceJustificative->chemin_stockage;

        $this->actingAs($this->user)->deleteJson($this->url())->assertNoContent();

        $this->assertNull(ExperienceProfessionnelle::find($this->experienceId)->piece_justificative_id);
        $this->assertSame(0, PieceJustificative::where('rattachement', 'experience')->count());
        Storage::disk('documents')->assertMissing($chemin);

        $this->actingAs($this->user)->deleteJson($this->url())->assertStatus(404);
    }

    public function test_supprimer_l_experience_supprime_son_justificatif(): void
    {
        // Amendement Lot 3b du DELETE /experiences/{id} de 3a.
        $this->actingAs($this->user)->post($this->url(), ['fichier' => $this->fichierPdf()])->assertCreated();
        $chemin = ExperienceProfessionnelle::find($this->experienceId)->pieceJustificative->chemin_stockage;

        $this->actingAs($this->user)->deleteJson("/api/candidatures/{$this->candidatureId}/experiences/{$this->experienceId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('experience_professionnelle', ['id' => $this->experienceId]);
        $this->assertSame(0, PieceJustificative::count());        // pas d'orphelin
        Storage::disk('documents')->assertMissing($chemin);        // pas de fichier orphelin
    }
}
