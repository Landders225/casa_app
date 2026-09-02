<?php

namespace Tests\Unit;

use App\Domain\Candidature\ValidateurCompletude;
use App\Models\Candidature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Candidat\CreeContexteCandidature;
use Tests\TestCase;

class ValidateurCompletudeTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    private ValidateurCompletude $validateur;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->validateur = app(ValidateurCompletude::class);
    }

    private function candidature(): Candidature
    {
        $user = $this->creerCandidat();
        $id = $this->actingAs($user)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');

        return Candidature::findOrFail($id);
    }

    public function test_dossier_complet_ne_renvoie_aucune_erreur(): void
    {
        $c = $this->rendreCandidatureComplete($this->candidature());

        $this->assertSame([], $this->validateur->verifier(
            $c->load('candidat', 'reponseFormulaire', 'experiences', 'piecesDossier'),
        ));
    }

    public function test_dossier_vierge_liste_tout_ce_qui_manque(): void
    {
        $c = $this->candidature()->load('candidat', 'reponseFormulaire', 'experiences', 'piecesDossier');

        $erreurs = $this->validateur->verifier($c);

        $this->assertArrayHasKey('cqp_confirme', $erreurs);
        $this->assertArrayHasKey('reponses.sc01_scolarise_actuellement', $erreurs);
        $this->assertArrayHasKey('reponses.mo04_lettre_motivation', $erreurs);
        $this->assertArrayHasKey('pieces_dossier', $erreurs);
        // se01 / se05 ne sont PAS requis (fidélité maquette, D-3c-3).
        $this->assertArrayNotHasKey('reponses.se01_vit_avec', $erreurs);
        $this->assertArrayNotHasKey('reponses.se05_personnes_a_charge', $erreurs);
    }

    public function test_lettre_trop_courte(): void
    {
        $c = $this->rendreCandidatureComplete($this->candidature(), ['mo04_lettre_motivation' => 'trop court']);

        $erreurs = $this->validateur->verifier($c->load('candidat', 'reponseFormulaire', 'experiences', 'piecesDossier'));
        $this->assertArrayHasKey('reponses.mo04_lettre_motivation', $erreurs);
    }

    public function test_conditionnels_sc05_non_et_se03_sans_emploi(): void
    {
        $c = $this->rendreCandidatureComplete($this->candidature(), [
            'sc05_beneficiaire_formation_actuelle' => 'non',
            'sc06_deja_beneficie_formation' => 'oui',   // -> exige sc07 + sc08
            'sc07_filiere_suivie' => null,
            'sc08_mene_a_terme' => null,
            'se03_situation_emploi' => 'sans_emploi',
            'se04_source_revenu' => null,               // -> exigé
        ]);

        $erreurs = $this->validateur->verifier($c->load('candidat', 'reponseFormulaire', 'experiences', 'piecesDossier'));
        $this->assertArrayHasKey('reponses.sc07_filiere_suivie', $erreurs);
        $this->assertArrayHasKey('reponses.sc08_mene_a_terme', $erreurs);
        $this->assertArrayHasKey('reponses.se04_source_revenu', $erreurs);
    }

    public function test_experience_sans_justificatif(): void
    {
        $c = $this->rendreCandidatureComplete($this->candidature());
        $c->experiences()->create(['domaine' => 'hotellerie', 'duree_categorie' => '6_12']); // pas de piece

        $erreurs = $this->validateur->verifier($c->fresh()->load('candidat', 'reponseFormulaire', 'experiences', 'piecesDossier'));
        $this->assertArrayHasKey('experiences.0.justificatif', $erreurs);
    }

    public function test_piece_de_dossier_manquante(): void
    {
        $c = $this->rendreCandidatureComplete($this->candidature());
        $c->piecesDossier()->where('type_document_code', 'diplome')->delete();

        $erreurs = $this->validateur->verifier($c->fresh()->load('candidat', 'reponseFormulaire', 'experiences', 'piecesDossier'));
        $this->assertArrayHasKey('pieces_dossier', $erreurs);
        $this->assertStringContainsString('diplome', $erreurs['pieces_dossier'][0]);
    }
}
