<?php

namespace Tests\Feature\Candidat;

use App\Models\PieceJustificative;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class TelechargementPieceTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    private User $user;

    private string $candidatureId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->user = $this->creerCandidat();
        $this->candidatureId = $this->actingAs($this->user)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');
    }

    public function test_le_proprietaire_telecharge_sa_piece(): void
    {
        $this->actingAs($this->user)->post(
            "/api/candidatures/{$this->candidatureId}/pieces/cni",
            ['fichier' => $this->fichierPdf('ma_cni.pdf')],
        );
        $piece = PieceJustificative::first();

        $response = $this->actingAs($this->user)->get("/api/pieces/{$piece->id}/download");

        $response->assertOk();
        $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse); // streaming, pas redirection
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('ma_cni.pdf', $response->headers->get('content-disposition'));

        // Le corps = les octets réels du fichier stocké.
        $attendu = Storage::disk('documents')->get($piece->chemin_stockage);
        $this->assertSame($attendu, $response->streamedContent());
    }

    public function test_telechargement_sans_authentification_401(): void
    {
        $this->actingAs($this->user)->post(
            "/api/candidatures/{$this->candidatureId}/pieces/cni",
            ['fichier' => $this->fichierPdf()],
        );
        $pieceId = PieceJustificative::first()->id;

        // Nouveau cycle applicatif : plus de guard résolu (cf. Lot 2).
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/pieces/{$pieceId}/download")->assertStatus(401);
    }

    public function test_download_d_un_id_inexistant_404(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/pieces/'.Str::uuid().'/download')
            ->assertStatus(404);
    }
}
