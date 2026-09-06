<?php

namespace Tests\Feature\Candidat;

use App\Models\PieceJustificative;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Un candidat A n'accède à AUCUNE pièce de B (dossier ou justificatif
 * d'expérience) : téléchargement, remplacement, suppression -> 404.
 */
class IsolationPieceTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    private User $a;

    private User $b;

    private string $candidatureB;

    private string $experienceB;

    private string $pieceDossierB;   // id piece CNI de B

    private string $pieceExpB;       // id justificatif d'expérience de B

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();

        $this->a = $this->creerCandidat('a@casa-demo.ci');
        $this->b = $this->creerCandidat('b@casa-demo.ci');

        $this->candidatureB = $this->actingAs($this->b)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');

        $this->actingAs($this->b)->post(
            "/api/candidatures/{$this->candidatureB}/pieces/cni",
            ['fichier' => $this->fichierPdf('cni_b.pdf')],
        );
        $this->pieceDossierB = PieceJustificative::where('candidature_id', $this->candidatureB)->value('id');

        $this->experienceB = $this->actingAs($this->b)->postJson(
            "/api/candidatures/{$this->candidatureB}/experiences",
            ['domaine' => 'hotellerie', 'duree_categorie' => '6_12'],
        )->json('data.id');
        $this->actingAs($this->b)->post(
            "/api/candidatures/{$this->candidatureB}/experiences/{$this->experienceB}/justificatif",
            ['fichier' => $this->fichierPdf('att_b.pdf')],
        );
        $this->pieceExpB = PieceJustificative::where('rattachement', 'experience')->value('id');
    }

    public function test_A_ne_telecharge_pas_la_piece_dossier_de_B(): void
    {
        $this->actingAs($this->a)->getJson("/api/pieces/{$this->pieceDossierB}/download")
            ->assertStatus(404);
    }

    public function test_A_ne_telecharge_pas_le_justificatif_experience_de_B(): void
    {
        $this->actingAs($this->a)->getJson("/api/pieces/{$this->pieceExpB}/download")
            ->assertStatus(404);
    }

    public function test_A_ne_remplace_pas_une_piece_de_B(): void
    {
        $this->actingAs($this->a)->post(
            "/api/candidatures/{$this->candidatureB}/pieces/cni",
            ['fichier' => $this->fichierPdf('pirate.pdf')],
        )->assertStatus(404);

        // Le fichier de B est intact (nom d'origine inchangé).
        $this->assertSame('cni_b.pdf', PieceJustificative::find($this->pieceDossierB)->nom_original);
    }

    public function test_A_ne_supprime_pas_une_piece_de_B(): void
    {
        $this->actingAs($this->a)->deleteJson("/api/candidatures/{$this->candidatureB}/pieces/cni")
            ->assertStatus(404);

        $this->assertDatabaseHas('piece_justificative', ['id' => $this->pieceDossierB]);
    }

    public function test_A_ne_liste_pas_les_pieces_de_B(): void
    {
        $this->actingAs($this->a)->getJson("/api/candidatures/{$this->candidatureB}/pieces")
            ->assertStatus(404);
    }
}
