<?php

namespace Tests\Feature\Evaluateur;

use App\Models\Candidature;
use App\Models\PieceJustificative;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TelechargementPieceEvaluateurTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private User $evaluateur;
    private Candidature $candidature;
    private string $pieceId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
        $candidat = $this->creerCandidat('cand@casa-demo.ci');

        $id = $this->actingAs($candidat)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');
        $this->candidature = \App\Models\Candidature::findOrFail($id);

        // pièce déposée par le candidat AVANT affectation (dossier encore en brouillon)
        $this->pieceId = $this->actingAs($candidat)->post(
            "/api/candidatures/{$id}/pieces/cni",
            ['fichier' => $this->fichierPdf('cni.pdf')],
        )->json('data.id');

        // puis affectation (action admin, simulée)
        $this->candidature = $this->affecter($this->candidature, $this->evaluateur);
    }

    public function test_evaluateur_affecte_telecharge_la_piece(): void
    {
        $response = $this->actingAs($this->evaluateur)
            ->get("/api/evaluateur/pieces/{$this->pieceId}/download");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_evaluateur_non_affecte_404(): void
    {
        $autre = $this->creerEvaluateur('autre@casa-demo.ci');

        $this->actingAs($autre)->getJson("/api/evaluateur/pieces/{$this->pieceId}/download")
            ->assertStatus(404);
    }

    public function test_admin_telecharge_n_importe_quelle_piece(): void
    {
        $admin = $this->creerAdmin('admin@casa-demo.ci');

        $this->actingAs($admin)->get("/api/evaluateur/pieces/{$this->pieceId}/download")->assertOk();
    }

    public function test_la_resource_evaluateur_ne_montre_pas_le_chemin_de_stockage(): void
    {
        $response = $this->actingAs($this->evaluateur)
            ->getJson("/api/evaluateur/candidatures/{$this->candidature->id}");

        $this->assertStringNotContainsString('chemin_stockage', $response->getContent());
        $chemin = PieceJustificative::find($this->pieceId)->chemin_stockage;
        $this->assertStringNotContainsString($chemin, $response->getContent());

        // l'url pointe la route évaluateur, pas la route candidat
        $response->assertJsonPath(
            'data.pieces_dossier.0.url',
            fn ($url) => str_starts_with($url, '/api/evaluateur/pieces/'),
        );
    }
}
