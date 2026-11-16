<?php

namespace Tests\Feature\Evaluateur;

use App\Models\Candidature;
use App\Models\JournalAudit;
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
        $this->candidature = Candidature::findOrFail($id);

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

    // --- Lot 19 — aperçu : EXACTEMENT la même matrice d'autorisation que /download. ---

    public function test_evaluateur_affecte_previsualise_la_piece(): void
    {
        $response = $this->actingAs($this->evaluateur)
            ->get("/api/evaluateur/pieces/{$this->pieceId}/apercu");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('x-content-type-options', 'nosniff');
        $response->assertHeader('content-security-policy', "sandbox; default-src 'none'");
        $this->assertStringContainsString('no-store', $response->headers->get('cache-control'));
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    public function test_evaluateur_non_affecte_404_sur_l_apercu(): void
    {
        $autre = $this->creerEvaluateur('autre@casa-demo.ci');

        $this->actingAs($autre)->getJson("/api/evaluateur/pieces/{$this->pieceId}/apercu")
            ->assertStatus(404);
    }

    public function test_admin_previsualise_n_importe_quelle_piece(): void
    {
        $admin = $this->creerAdmin('admin@casa-demo.ci');

        $this->actingAs($admin)->get("/api/evaluateur/pieces/{$this->pieceId}/apercu")->assertOk();
    }

    public function test_apercu_d_un_fichier_absent_du_disque_404_message_clair(): void
    {
        Storage::disk('documents')->delete(PieceJustificative::find($this->pieceId)->chemin_stockage);

        $this->actingAs($this->evaluateur)
            ->getJson("/api/evaluateur/pieces/{$this->pieceId}/apercu")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Fichier introuvable.');
    }

    // --- Lot 19 — journalisation dédupliquée (aperçu ET téléchargement direct). ---

    public function test_l_apercu_cree_une_ligne_d_audit_minimale(): void
    {
        $this->actingAs($this->evaluateur)->get("/api/evaluateur/pieces/{$this->pieceId}/apercu");

        $this->assertSame(1, JournalAudit::where('action', 'Consultation pièce')->count());
        $ligne = JournalAudit::where('action', 'Consultation pièce')->first();
        $this->assertSame('Documents', $ligne->module);
        $this->assertSame($this->candidature->numero_dossier, $ligne->objet);
        $this->assertSame('cni', $ligne->nouvelle_valeur);
        // Contenu MINIMAL : jamais le nom de fichier déposé par le candidat.
        $this->assertNull($ligne->ancienne_valeur);
    }

    public function test_deux_apercus_le_meme_jour_ne_produisent_qu_une_seule_ligne(): void
    {
        $this->actingAs($this->evaluateur)->get("/api/evaluateur/pieces/{$this->pieceId}/apercu");
        $this->actingAs($this->evaluateur)->get("/api/evaluateur/pieces/{$this->pieceId}/apercu");

        $this->assertSame(1, JournalAudit::where('action', 'Consultation pièce')->count());
    }

    public function test_le_telechargement_direct_est_journalise_comme_l_apercu(): void
    {
        $this->actingAs($this->evaluateur)->get("/api/evaluateur/pieces/{$this->pieceId}/download");

        $this->assertSame(1, JournalAudit::where('action', 'Consultation pièce')->count());
    }

    public function test_apercu_puis_telechargement_le_meme_jour_ne_produisent_qu_une_seule_ligne(): void
    {
        $this->actingAs($this->evaluateur)->get("/api/evaluateur/pieces/{$this->pieceId}/apercu");
        $this->actingAs($this->evaluateur)->get("/api/evaluateur/pieces/{$this->pieceId}/download");

        $this->assertSame(1, JournalAudit::where('action', 'Consultation pièce')->count());
    }

    public function test_deux_pieces_differentes_du_meme_dossier_le_meme_jour_donnent_deux_lignes(): void
    {
        // Deux pièces de DOSSIER (types différents), déposées AVANT affectation
        // (dossier encore en brouillon — `candidature.modifiable`), comme setUp().
        $candidat = $this->creerCandidat('deux-pieces@casa-demo.ci');
        $candidatureId = $this->actingAs($candidat)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');
        $pieceCni = $this->actingAs($candidat)->post(
            "/api/candidatures/{$candidatureId}/pieces/cni",
            ['fichier' => $this->fichierPdf('cni.pdf')],
        )->json('data.id');
        $pieceDiplome = $this->actingAs($candidat)->post(
            "/api/candidatures/{$candidatureId}/pieces/diplome",
            ['fichier' => $this->fichierPdf('diplome.pdf')],
        )->json('data.id');
        $this->affecter(Candidature::findOrFail($candidatureId), $this->evaluateur);

        $this->actingAs($this->evaluateur)->get("/api/evaluateur/pieces/{$pieceCni}/apercu")->assertOk();
        $this->actingAs($this->evaluateur)->get("/api/evaluateur/pieces/{$pieceDiplome}/apercu")->assertOk();

        $this->assertSame(2, JournalAudit::where('action', 'Consultation pièce')->count());
        $this->assertSame(
            ['cni', 'diplome'],
            JournalAudit::where('action', 'Consultation pièce')->orderBy('nouvelle_valeur')->pluck('nouvelle_valeur')->all(),
        );
    }

    public function test_justificatif_d_experience_journalise_avec_un_libelle_generique(): void
    {
        // Expérience + justificatif déposés AVANT affectation, même contrainte
        // `candidature.modifiable` que ci-dessus.
        $candidat = $this->creerCandidat('exp-piece@casa-demo.ci');
        $candidatureId = $this->actingAs($candidat)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');
        $experienceId = $this->actingAs($candidat)->postJson(
            "/api/candidatures/{$candidatureId}/experiences",
            ['domaine' => 'hotellerie', 'duree_categorie' => '6_12'],
        )->json('data.id');
        $pieceExpId = $this->actingAs($candidat)->post(
            "/api/candidatures/{$candidatureId}/experiences/{$experienceId}/justificatif",
            ['fichier' => $this->fichierPdf('attestation.pdf')],
        )->json('data.id');
        $this->affecter(Candidature::findOrFail($candidatureId), $this->evaluateur);

        $this->actingAs($this->evaluateur)->get("/api/evaluateur/pieces/{$pieceExpId}/apercu")->assertOk();

        $ligne = JournalAudit::where('action', 'Consultation pièce')->first();
        $this->assertNotNull($ligne);
        $this->assertSame('justificatif_experience', $ligne->nouvelle_valeur);
        // Jamais le nom du fichier déposé par le candidat (peut contenir une identité).
        $this->assertStringNotContainsString('attestation', (string) $ligne->nouvelle_valeur);
    }
}
