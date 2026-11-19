<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Api\Admin\RetablissementEligibiliteController;
use App\Models\Campagne;
use App\Models\Candidature;
use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rétablissement d'éligibilité (Lot 4, ADR-49) — l'administration rend
 * éligible un dossier `non_eligible` (rejet automatique OU élimination
 * manuelle) de son choix. Motif obligatoire, pré-publication uniquement
 * (même garde que l'affectation/réattribution), atomique, 1 ligne d'audit
 * par dossier.
 */
class RetablissementEligibiliteTest extends TestCase
{
    use CreeContexteClassement;
    use RefreshDatabase;

    private Campagne $campagne;

    private User $admin;

    private User $evaluateur;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedContexteClassement();
        $this->campagne = Campagne::ouverte()->firstOrFail();
        $this->admin = $this->creerAdmin('admin@casa-demo.ci');
        $this->evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
    }

    private function retablir(array $ids, string $motif = 'Pièces complémentaires reçues confirmant l’éligibilité.')
    {
        return $this->actingAs($this->admin)->postJson('/api/admin/candidatures/eligibilite/retablir', [
            'candidature_ids' => $ids,
            'motif' => $motif,
        ]);
    }

    /** Dossier rejeté AUTOMATIQUEMENT à la soumission (DI.01), via le vrai parcours candidat. */
    private function candidatureRejeteeALaSoumission(): Candidature
    {
        $candidat = $this->creerCandidat('rejete@casa-demo.ci');
        $id = $this->actingAs($candidat)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');
        $candidature = $this->rendreCandidatureComplete(
            Candidature::findOrFail($id),
            ['di01_disponible_lun_ven' => 'non'],
        );
        $this->actingAs($candidat)->postJson("/api/candidatures/{$candidature->id}/soumettre")->assertOk();

        return $candidature->fresh();
    }

    /** Dossier ÉLIMINÉ MANUELLEMENT en cours d'instruction (`statut_interne` reste `en_instruction`). */
    private function candidatureElimineeEnInstruction(): Candidature
    {
        $candidature = $this->candidatureAffectee($this->creerCandidat('instr@casa-demo.ci'), $this->evaluateur);
        $this->actingAs($this->admin)->postJson("/api/admin/candidatures/{$candidature->id}/elimination", [
            'motif' => 'Pièce suspecte à vérifier.',
        ])->assertOk();

        return $candidature->fresh();
    }

    /** Dossier ÉLIMINÉ MANUELLEMENT après évaluation complète (`statut_interne` reste `evalue`). */
    private function candidatureElimineeApresEvaluation(): Candidature
    {
        $candidature = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 55.0, 28.0);
        $this->actingAs($this->admin)->postJson("/api/admin/candidatures/{$candidature->id}/elimination", [
            'motif' => 'Doute sur une pièce, vérifié depuis.',
        ])->assertOk();

        return $candidature->fresh();
    }

    // --- Rétablissement nominal, par cas de `statut_interne`. ---

    public function test_retablit_un_dossier_rejete_a_la_soumission(): void
    {
        $candidature = $this->candidatureRejeteeALaSoumission();
        $this->assertSame('non_eligible', $candidature->statut_interne);

        $this->retablir([$candidature->id], 'Attestation complémentaire reçue : disponibilité confirmée.')
            ->assertOk()
            ->assertJsonPath('data.retablies', 1);

        $fraiche = $candidature->fresh();
        $this->assertSame('eligible', $fraiche->statut_eligibilite_interne);
        $this->assertSame('soumis', $fraiche->statut_interne);
        $this->assertTrue($fraiche->eligibilite_retablie);
        $this->assertNotNull($fraiche->eligibilite_retablie_le);
        $this->assertSame($this->admin->membreEquipe->id, $fraiche->eligibilite_retablie_par);

        $this->assertDatabaseHas('journal_audit', [
            'action' => "Rétablissement d'éligibilité",
            'module' => 'Candidatures',
            'objet' => $candidature->numero_dossier,
            'ancienne_valeur' => 'éligibilité: non_eligible · statut: non_eligible',
            'nouvelle_valeur' => 'éligibilité: eligible · statut: soumis',
            'motif' => 'Attestation complémentaire reçue : disponibilité confirmée.',
        ]);
    }

    public function test_retablit_un_dossier_elimine_en_instruction_statut_interne_inchange(): void
    {
        $candidature = $this->candidatureElimineeEnInstruction();
        $this->assertSame('en_instruction', $candidature->statut_interne);

        $this->retablir([$candidature->id])->assertOk();

        $fraiche = $candidature->fresh();
        $this->assertSame('eligible', $fraiche->statut_eligibilite_interne);
        $this->assertSame('en_instruction', $fraiche->statut_interne);
        $this->assertTrue($fraiche->eligibilite_retablie);
    }

    public function test_retablit_un_dossier_elimine_apres_evaluation_marque_le_classement_perime(): void
    {
        $candidature = $this->candidatureElimineeApresEvaluation();
        $this->assertSame('evalue', $candidature->statut_interne);
        $this->assertFalse($this->campagne->fresh()->classement_perime);

        $this->retablir([$candidature->id])->assertOk();

        $fraiche = $candidature->fresh();
        $this->assertSame('eligible', $fraiche->statut_eligibilite_interne);
        $this->assertSame('evalue', $fraiche->statut_interne);
        $this->assertTrue($this->campagne->fresh()->classement_perime);
    }

    public function test_un_retablissement_qui_ne_concerne_pas_un_statut_evalue_ne_touche_pas_classement_perime(): void
    {
        $candidature = $this->candidatureElimineeEnInstruction();

        $this->retablir([$candidature->id])->assertOk();

        $this->assertFalse($this->campagne->fresh()->classement_perime);
    }

    // --- Refus. ---

    public function test_deja_eligible_422_rien_ecrit(): void
    {
        $eligible = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);

        $this->retablir([$eligible->id])->assertStatus(422)->assertJsonValidationErrors('candidature_ids');

        $this->assertSame(0, JournalAudit::where('action', "Rétablissement d'éligibilité")->count());
    }

    public function test_campagne_publiee_409_rien_ecrit(): void
    {
        $candidature = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 55.0, 28.0);
        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/classement")->assertOk();
        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/publier")->assertOk();
        // Élimination manuelle reste possible après publication (ADR-15).
        $this->actingAs($this->admin)->postJson("/api/admin/candidatures/{$candidature->id}/elimination", [
            'motif' => 'Fraude découverte après publication.',
        ])->assertOk();

        $this->retablir([$candidature->id])->assertStatus(409);

        $this->assertSame('non_eligible', $candidature->fresh()->statut_eligibilite_interne);
        $this->assertSame(0, JournalAudit::where('action', "Rétablissement d'éligibilité")->count());
    }

    public function test_motif_absent_422(): void
    {
        $candidature = $this->candidatureRejeteeALaSoumission();

        $this->actingAs($this->admin)->postJson('/api/admin/candidatures/eligibilite/retablir', [
            'candidature_ids' => [$candidature->id],
        ])->assertStatus(422)->assertJsonValidationErrors('motif');
    }

    public function test_candidature_ids_vide_422(): void
    {
        $this->retablir([])->assertStatus(422)->assertJsonValidationErrors('candidature_ids');
    }

    public function test_au_dela_du_plafond_422(): void
    {
        $ids = array_map(fn () => (string) Str::uuid(), range(1, RetablissementEligibiliteController::PLAFOND_DOSSIERS + 1));

        $this->retablir($ids)->assertStatus(422)->assertJsonValidationErrors('candidature_ids');
    }

    public function test_reserve_a_l_administrateur(): void
    {
        $candidature = $this->candidatureElimineeEnInstruction();
        $candidat = $this->creerCandidat('intrus@casa-demo.ci');

        foreach ([$this->evaluateur, $candidat] as $intrus) {
            $this->actingAs($intrus)->postJson('/api/admin/candidatures/eligibilite/retablir', [
                'candidature_ids' => [$candidature->id],
                'motif' => 'Tentative.',
            ])->assertForbidden();
        }

        $this->assertSame('non_eligible', $candidature->fresh()->statut_eligibilite_interne);
    }

    // --- Concurrence (séquentielle — PHPUnit est synchrone, cf. ADR-49). ---

    public function test_deux_appels_successifs_le_second_est_refuse_sans_seconde_ligne_d_audit(): void
    {
        $candidature = $this->candidatureElimineeEnInstruction();

        $this->retablir([$candidature->id])->assertOk();
        $this->assertSame(1, JournalAudit::where('action', "Rétablissement d'éligibilité")->count());

        $this->retablir([$candidature->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('candidature_ids');

        $this->assertSame(1, JournalAudit::where('action', "Rétablissement d'éligibilité")->count());
    }

    // --- Non-régression : priorité sur le recalcul automatique. ---

    public function test_la_verification_evaluateur_ne_relance_pas_un_dossier_retabli(): void
    {
        $candidature = $this->candidatureElimineeEnInstruction();
        $this->retablir([$candidature->id])->assertOk();

        // Une nouvelle vérification qui déclencherait normalement SC.04 (cepe)
        // ne doit PAS faire basculer le verdict rétabli.
        $this->actingAs($this->evaluateur)
            ->putJson("/api/evaluateur/candidatures/{$candidature->id}/verification", [
                'nationalite_confirmee' => true,
                'diplome_verifie' => 'cepe',
            ])
            ->assertOk()
            ->assertJsonPath('data.statut_eligibilite_interne', 'eligible')
            ->assertJsonPath('data.criteres_eliminatoires', []);

        $this->assertSame('eligible', $candidature->fresh()->statut_eligibilite_interne);
        // Le critère est quand même tracé (rien caché), juste sans effet sur le verdict.
        $this->assertDatabaseHas('critere_eliminatoire_declenche', [
            'candidature_id' => $candidature->id,
            'code_critere' => 'SC.04',
        ]);
    }

    public function test_la_correction_exceptionnelle_ne_relance_pas_un_dossier_retabli(): void
    {
        $candidature = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 55.0, 28.0);
        $this->actingAs($this->admin)->postJson("/api/admin/candidatures/{$candidature->id}/elimination", [
            'motif' => 'Doute, vérifié depuis.',
        ])->assertOk();
        $this->retablir([$candidature->id])->assertOk();

        $this->actingAs($this->admin)->postJson("/api/admin/candidatures/{$candidature->id}/correction/dossier", [
            'motif' => 'Correction sans lien avec l’éligibilité.',
            'reponses' => ['di01_disponible_lun_ven' => 'non'], // déclencherait DI.01
        ])->assertOk()->assertJsonPath('data.statut_eligibilite_interne', 'eligible');

        $this->assertSame('eligible', $candidature->fresh()->statut_eligibilite_interne);
    }

    // --- Réversibilité : seule l'élimination manuelle renverse. ---

    public function test_une_nouvelle_elimination_manuelle_renverse_le_retablissement(): void
    {
        $candidature = $this->candidatureElimineeEnInstruction();
        $this->retablir([$candidature->id])->assertOk();
        $this->assertTrue($candidature->fresh()->eligibilite_retablie);

        $this->actingAs($this->admin)->postJson("/api/admin/candidatures/{$candidature->id}/elimination", [
            'motif' => 'Nouvel élément découvert.',
        ])->assertOk();

        $fraiche = $candidature->fresh();
        $this->assertSame('non_eligible', $fraiche->statut_eligibilite_interne);
        $this->assertFalse($fraiche->eligibilite_retablie);
        $this->assertNull($fraiche->eligibilite_retablie_le);
        $this->assertNull($fraiche->eligibilite_retablie_par);

        // Et redevient restaurable normalement.
        $this->retablir([$candidature->id])->assertOk();
        $this->assertTrue($candidature->fresh()->eligibilite_retablie);
    }
}
