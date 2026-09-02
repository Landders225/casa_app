<?php

namespace Tests\Feature\Candidat;

use App\Models\PieceJustificative;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecuriteUploadTest extends TestCase
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

    private function deposerCni(UploadedFile $fichier)
    {
        return $this->actingAs($this->user)->post(
            "/api/candidatures/{$this->candidatureId}/pieces/cni",
            ['fichier' => $fichier],
        );
    }

    public function test_executable_deguise_en_pdf_rejete(): void
    {
        // Contenu = exécutable (MZ), nom = .pdf, Content-Type déclaré = application/pdf.
        // finfo -> application/x-dosexec -> mimetypes échoue.
        $exe = $this->fichierReel('cni.pdf', 'MZ'.str_repeat("\x00", 200), 'application/pdf');

        $this->deposerCni($exe)->assertStatus(422)->assertJsonValidationErrors('fichier');
        $this->assertSame(0, PieceJustificative::count());
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    public function test_extension_exe_rejetee(): void
    {
        // Vrai PDF mais nom en .exe -> garde-fou `extensions` échoue.
        $this->deposerCni($this->fichierPdf('malware.exe'))
            ->assertStatus(422)->assertJsonValidationErrors('fichier');
    }

    public function test_pdf_au_contenu_falsifie_rejete(): void
    {
        $faux = $this->fichierReel('cni.pdf', 'Ceci est un fichier texte, pas un PDF.', 'application/pdf');

        $this->deposerCni($faux)->assertStatus(422)->assertJsonValidationErrors('fichier');
        $this->assertSame(0, PieceJustificative::count());
    }

    public function test_depassement_de_taille_rejete(): void
    {
        // Vrai PDF (finfo -> application/pdf) mais > 10 Mo : seul `max` doit échouer.
        $gros = $this->fichierReel('cni.pdf', $this->contenuPdf().str_repeat('A', 11 * 1024 * 1024), 'application/pdf');

        $this->deposerCni($gros)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fichier' => 'Le fichier dépasse la taille maximale de 10 Mo.']);
        $this->assertSame(0, PieceJustificative::count());
    }

    public function test_nom_de_fichier_traversant_neutralise(): void
    {
        $this->deposerCni($this->fichierReel('../../../../etc/passwd.pdf', $this->contenuPdf(), 'application/pdf'))
            ->assertCreated();

        $piece = PieceJustificative::first();
        // nom_original = basename assaini, sans séparateur de chemin.
        $this->assertSame('passwd.pdf', $piece->nom_original);
        $this->assertStringNotContainsString('..', $piece->chemin_stockage);
        $this->assertStringNotContainsString('etc/', $piece->chemin_stockage);
        // Fichier écrit uniquement sous {candidature_id}/{uuid}.pdf
        $this->assertMatchesRegularExpression(
            '#^'.$this->candidatureId.'/[0-9a-f-]{36}\.pdf$#',
            $piece->chemin_stockage,
        );
    }

    public function test_extension_serveur_derive_du_mime_pas_du_nom(): void
    {
        // Contenu PNG, nom .jpg -> stocké en .png (extension = MIME détecté par contenu).
        $this->deposerCni($this->fichierReel('trompeur.jpg', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 'image/jpeg'))
            ->assertCreated();

        $this->assertStringEndsWith('.png', PieceJustificative::first()->chemin_stockage);
    }
}
