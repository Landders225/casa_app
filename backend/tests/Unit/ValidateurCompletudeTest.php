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

    // --- Lot 18 — CMU (numéro + justificatif) ---

    public function test_numero_cmu_manquant_bloque_la_completude(): void
    {
        $candidature = $this->candidature();
        $candidature->candidat->forceFill(['numero_cmu' => null])->save();

        $c = $this->rendreCandidatureComplete($candidature);

        $erreurs = $this->validateur->verifier($c->fresh()->load('candidat', 'reponseFormulaire', 'experiences', 'piecesDossier'));
        $this->assertArrayHasKey('identite.numero_cmu', $erreurs);
    }

    public function test_justificatif_cmu_manquant_bloque_la_completude(): void
    {
        $c = $this->rendreCandidatureComplete($this->candidature());
        $c->piecesDossier()->where('type_document_code', 'cmu')->delete();

        $erreurs = $this->validateur->verifier($c->fresh()->load('candidat', 'reponseFormulaire', 'experiences', 'piecesDossier'));
        $this->assertArrayHasKey('pieces_dossier', $erreurs);
        $this->assertStringContainsString('cmu', $erreurs['pieces_dossier'][0]);
    }

    public function test_numero_cmu_et_justificatif_presents_ne_bloquent_rien(): void
    {
        $candidature = $this->candidature();
        $candidature->candidat->forceFill(['numero_cmu' => 'CMU000111222'])->save();

        $c = $this->rendreCandidatureComplete($candidature);

        $this->assertSame([], $this->validateur->verifier(
            $c->fresh()->load('candidat', 'reponseFormulaire', 'experiences', 'piecesDossier'),
        ));
    }

    // --- Lot D — retrait de residence/lettre de l'obligation ---

    public function test_dossier_complet_sans_residence_ni_lettre_ne_renvoie_aucune_erreur(): void
    {
        // `rendreCandidatureComplete` dépose exactement `TYPES_DOSSIER` (5,
        // Lot D) — aucune pièce `residence`/`lettre` ici, volontairement.
        $c = $this->rendreCandidatureComplete($this->candidature());
        $this->assertDatabaseMissing('piece_justificative', ['type_document_code' => 'residence']);
        $this->assertDatabaseMissing('piece_justificative', ['type_document_code' => 'lettre']);

        $this->assertSame([], $this->validateur->verifier(
            $c->fresh()->load('candidat', 'reponseFormulaire', 'experiences', 'piecesDossier'),
        ));
    }

    /**
     * Un dossier qui a DÉJÀ une pièce retirée (déposée avant le Lot D, ou
     * via l'API malgré tout — la route reste permissive) reste complet : la
     * présence d'une pièce hors `TYPES_DOSSIER` n'ajoute ni ne retire rien
     * au diff de `verifier()`.
     */
    public function test_piece_retiree_deja_presente_ne_perturbe_pas_la_completude(): void
    {
        $c = $this->rendreCandidatureComplete($this->candidature());
        $c->piecesDossier()->create([
            'type_document_code' => 'residence',
            'rattachement' => 'dossier',
            'nom_original' => 'residence.pdf',
            'chemin_stockage' => $c->id.'/residence-legacy.pdf',
            'taille_octets' => 500,
            'type_mime' => 'application/pdf',
            'depose_le' => now(),
        ]);

        $this->assertSame([], $this->validateur->verifier(
            $c->fresh()->load('candidat', 'reponseFormulaire', 'experiences', 'piecesDossier'),
        ));
    }
}
