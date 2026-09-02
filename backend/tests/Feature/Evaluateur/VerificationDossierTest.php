<?php

namespace Tests\Feature\Evaluateur;

use App\Models\Candidature;
use App\Models\CritereEliminatoireDeclenche;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VerificationDossierTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private User $evaluateur;
    private Candidature $candidature;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
        $this->candidature = $this->candidatureAffectee(
            $this->creerCandidat('cand@casa-demo.ci'),
            $this->evaluateur,
        );
    }

    private function verifier(array $data)
    {
        return $this->actingAs($this->evaluateur)
            ->putJson("/api/evaluateur/candidatures/{$this->candidature->id}/verification", $data);
    }

    public function test_saisie_de_verification_conforme_confirme_l_eligibilite(): void
    {
        $this->verifier(['nationalite_confirmee' => true, 'diplome_verifie' => 'bac'])
            ->assertOk()
            ->assertJsonPath('data.verification.nationalite_confirmee', true)
            ->assertJsonPath('data.verification.diplome_verifie', 'bac')
            ->assertJsonPath('data.verification.verifie_par.prenom', 'Eval')
            ->assertJsonPath('data.statut_eligibilite_interne', 'eligible')
            ->assertJsonPath('data.statut_interne', 'en_instruction');

        $this->assertDatabaseHas('verification_dossier', [
            'candidature_id' => $this->candidature->id,
            'diplome_verifie' => 'bac',
            'verifie_par' => $this->evaluateur->membreEquipe->id,
        ]);
    }

    public function test_diplome_cepe_declenche_le_critere_SC04(): void
    {
        $reponse = $this->verifier(['nationalite_confirmee' => true, 'diplome_verifie' => 'cepe'])->assertOk();

        // Le critère est tracé, origine évaluateur.
        $critere = CritereEliminatoireDeclenche::where('candidature_id', $this->candidature->id)->firstOrFail();
        $this->assertSame('SC.04', $critere->code_critere);
        $this->assertSame('verification_evaluateur', $critere->origine);

        // Le verdict bascule ; le statut workflow ne bouge pas.
        $this->assertSame('non_eligible', $this->candidature->fresh()->statut_eligibilite_interne);
        $this->assertSame('en_instruction', $this->candidature->fresh()->statut_interne);

        // L'évaluateur voit pourquoi.
        $reponse->assertJsonPath('data.statut_eligibilite_interne', 'non_eligible')
            ->assertJsonPath('data.criteres_eliminatoires.0.code_critere', 'SC.04')
            ->assertJsonPath('data.criteres_eliminatoires.0.origine', 'verification_evaluateur');
    }

    public function test_nationalite_non_confirmee_declenche_le_critere(): void
    {
        $this->verifier(['nationalite_confirmee' => false])->assertOk()
            ->assertJsonPath('data.criteres_eliminatoires.0.code_critere', 'nationalite')
            ->assertJsonPath('data.statut_eligibilite_interne', 'non_eligible');
    }

    public function test_re_verification_est_idempotente(): void
    {
        // 1er passage : cepe -> critère SC.04
        $this->verifier(['diplome_verifie' => 'cepe'])->assertOk();
        $this->assertSame(1, CritereEliminatoireDeclenche::where('candidature_id', $this->candidature->id)->count());

        // Correction : bac -> le SC.04 disparaît, retour à eligible
        $this->verifier(['diplome_verifie' => 'bac'])->assertOk()
            ->assertJsonPath('data.statut_eligibilite_interne', 'eligible')
            ->assertJsonPath('data.criteres_eliminatoires', []);
        $this->assertSame(0, CritereEliminatoireDeclenche::where('candidature_id', $this->candidature->id)->count());
    }

    public function test_verification_ne_touche_pas_aux_criteres_de_soumission(): void
    {
        // Un critère "soumission_candidat" pré-existant (cas défensif).
        CritereEliminatoireDeclenche::create([
            'candidature_id' => $this->candidature->id,
            'code_critere' => 'DI.01', 'detail' => 'test', 'origine' => 'soumission_candidat', 'declenche_le' => now(),
        ]);

        $this->verifier(['diplome_verifie' => 'bac'])->assertOk();

        $this->assertDatabaseHas('critere_eliminatoire_declenche', [
            'candidature_id' => $this->candidature->id, 'origine' => 'soumission_candidat',
        ]);
        // Le critère soumission subsiste -> reste non_eligible.
        $this->assertSame('non_eligible', $this->candidature->fresh()->statut_eligibilite_interne);
    }

    public function test_journal_audit_ecrit_pour_la_verification(): void
    {
        $this->verifier(['diplome_verifie' => 'cepe'])->assertOk();

        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $this->evaluateur->id,
            'action' => 'Vérification du dossier',
            'module' => 'Évaluation',
            'ancienne_valeur' => 'eligible',
            'nouvelle_valeur' => 'non_eligible',
        ]);
    }

    public function test_valeur_de_diplome_invalide_rejetee_422(): void
    {
        $this->verifier(['diplome_verifie' => 'licence'])
            ->assertStatus(422)->assertJsonValidationErrors('diplome_verifie');
    }

    public function test_verification_impossible_si_dossier_evalue_409(): void
    {
        $this->candidature->forceFill(['statut_interne' => 'evalue'])->saveQuietly();

        $this->verifier(['diplome_verifie' => 'bac'])->assertStatus(409);
    }

    public function test_verification_par_un_evaluateur_non_affecte_404(): void
    {
        $autre = $this->creerEvaluateur('autre@casa-demo.ci');

        $this->actingAs($autre)
            ->putJson("/api/evaluateur/candidatures/{$this->candidature->id}/verification", ['diplome_verifie' => 'bac'])
            ->assertStatus(404);
    }

    public function test_admin_peut_verifier_un_dossier_non_affecte_a_lui(): void
    {
        $admin = $this->creerAdmin('admin@casa-demo.ci');

        $this->actingAs($admin)
            ->putJson("/api/evaluateur/candidatures/{$this->candidature->id}/verification", ['diplome_verifie' => 'bac'])
            ->assertOk();
    }
}
