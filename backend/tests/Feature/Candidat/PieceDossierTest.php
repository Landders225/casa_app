<?php

namespace Tests\Feature\Candidat;

use App\Models\PieceJustificative;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PieceDossierTest extends TestCase
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

    public function test_depot_d_une_piece_de_dossier(): void
    {
        $response = $this->actingAs($this->user)->post(
            "/api/candidatures/{$this->candidatureId}/pieces/cni",
            ['fichier' => $this->fichierPdf('ma_cni.pdf')],
        );

        $response->assertCreated()
            ->assertJsonPath('data.rattachement', 'dossier')
            ->assertJsonPath('data.type_document_code', 'cni')
            ->assertJsonPath('data.nom_original', 'ma_cni.pdf')
            ->assertJsonPath('data.type_mime', 'application/pdf');

        $piece = PieceJustificative::firstWhere('type_document_code', 'cni');
        $this->assertSame('dossier', $piece->rattachement);
        $this->assertSame($this->candidatureId, $piece->candidature_id);
        Storage::disk('documents')->assertExists($piece->chemin_stockage);
        // Nom de stockage = uuid serveur, PAS le nom client.
        $this->assertMatchesRegularExpression(
            '#^'.$this->candidatureId.'/[0-9a-f-]{36}\.pdf$#',
            $piece->chemin_stockage,
        );
    }

    public function test_remplacement_ne_duplique_pas(): void
    {
        $this->actingAs($this->user)->post(
            "/api/candidatures/{$this->candidatureId}/pieces/cni",
            ['fichier' => $this->fichierPdf('v1.pdf')],
        )->assertCreated();

        $ancienChemin = PieceJustificative::firstWhere('type_document_code', 'cni')->chemin_stockage;

        $this->actingAs($this->user)->post(
            "/api/candidatures/{$this->candidatureId}/pieces/cni",
            ['fichier' => $this->fichierPng('v2.png')],
        )->assertOk()->assertJsonPath('data.nom_original', 'v2.png');

        // Toujours UNE seule ligne pour ce type.
        $this->assertSame(1, PieceJustificative::where('type_document_code', 'cni')->count());

        $piece = PieceJustificative::firstWhere('type_document_code', 'cni');
        $this->assertSame('image/png', $piece->type_mime);
        // L'ancien fichier a été supprimé, le nouveau existe.
        Storage::disk('documents')->assertMissing($ancienChemin);
        Storage::disk('documents')->assertExists($piece->chemin_stockage);
    }

    public function test_les_6_types_du_referentiel_sont_acceptes(): void
    {
        foreach (['cni', 'residence', 'diplome', 'cv', 'lettre', 'photo'] as $type) {
            $this->actingAs($this->user)->post(
                "/api/candidatures/{$this->candidatureId}/pieces/{$type}",
                ['fichier' => $this->fichierPdf("{$type}.pdf")],
            )->assertCreated();
        }

        $this->assertSame(6, PieceJustificative::where('candidature_id', $this->candidatureId)->count());
    }

    public function test_type_hors_referentiel_donne_404(): void
    {
        $this->actingAs($this->user)->post(
            "/api/candidatures/{$this->candidatureId}/pieces/achevement",
            ['fichier' => $this->fichierPdf()],
        )->assertStatus(404);
    }

    public function test_suppression_d_une_piece(): void
    {
        $this->actingAs($this->user)->post(
            "/api/candidatures/{$this->candidatureId}/pieces/cv",
            ['fichier' => $this->fichierPdf('cv.pdf')],
        );
        $chemin = PieceJustificative::firstWhere('type_document_code', 'cv')->chemin_stockage;

        $this->actingAs($this->user)->deleteJson("/api/candidatures/{$this->candidatureId}/pieces/cv")
            ->assertNoContent();

        $this->assertDatabaseMissing('piece_justificative', ['type_document_code' => 'cv']);
        Storage::disk('documents')->assertMissing($chemin);

        // Re-supprimer -> 404.
        $this->actingAs($this->user)->deleteJson("/api/candidatures/{$this->candidatureId}/pieces/cv")
            ->assertStatus(404);
    }

    public function test_liste_des_pieces(): void
    {
        $this->actingAs($this->user)->post(
            "/api/candidatures/{$this->candidatureId}/pieces/cni",
            ['fichier' => $this->fichierPdf('cni.pdf')],
        );

        $this->actingAs($this->user)->getJson("/api/candidatures/{$this->candidatureId}/pieces")
            ->assertOk()
            ->assertJsonPath('data.dossier.0.type_document_code', 'cni')
            ->assertJsonStructure(['data' => ['dossier', 'experiences']]);
    }

    public function test_aucune_reponse_n_expose_chemin_stockage(): void
    {
        $put = $this->actingAs($this->user)->post(
            "/api/candidatures/{$this->candidatureId}/pieces/cni",
            ['fichier' => $this->fichierPdf('cni.pdf')],
        );
        $liste = $this->actingAs($this->user)->getJson("/api/candidatures/{$this->candidatureId}/pieces");
        $candidature = $this->actingAs($this->user)->getJson("/api/candidatures/{$this->candidatureId}");

        foreach ([$put, $liste, $candidature] as $r) {
            $this->assertStringNotContainsString('chemin_stockage', $r->getContent());
        }
        // Le chemin réel (uuid) ne fuit pas non plus.
        $chemin = PieceJustificative::firstWhere('type_document_code', 'cni')->chemin_stockage;
        $this->assertStringNotContainsString($chemin, $put->getContent());
    }
}
