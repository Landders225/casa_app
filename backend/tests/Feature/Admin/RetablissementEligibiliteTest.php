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

    // --- Preuve de bout en bout (revue Étape A, point 1). ---

    /**
     * Dossier rejeté à la soumission → rétabli → (a) affectable via
     * l'affectation MANUELLE réelle, (b) compté dans l'aperçu de l'affectation
     * AUTOMATIQUE (nombre_affectables +1, ignores.non_eligibles -1), (c)
     * l'évaluateur affecté ouvre la fiche et enregistre sa vérification (200).
     */
    public function test_e2e_dossier_rejete_a_la_soumission_retabli_devient_affectable_et_verifiable(): void
    {
        $candidature = $this->candidatureRejeteeALaSoumission();
        $this->assertSame('non_eligible', $candidature->statut_interne);

        // Un second dossier TOUJOURS affectable (filler) : sans lui, l'aperçu
        // « AVANT » n'aurait RIEN à affecter (422 `rien_a_affecter`, comportement
        // normal de ServiceAffectationAutomatique) — il ne servirait donc à rien
        // pour démontrer le delta nombre_affectables/ignores.non_eligibles.
        $filler = Candidature::create([
            'candidat_id' => $this->creerCandidat('filler-lot4@casa-demo.ci')->candidat->id,
            'campagne_id' => $this->campagne->id,
            'filiere_id' => $this->idFiliere('cuisine'),
            'numero_dossier' => 'CASA-FILLER-000001',
            'cqp_confirme' => true,
        ]);
        $filler->forceFill([
            'statut_interne' => 'soumis',
            'statut_eligibilite_interne' => 'eligible',
            'date_soumission' => now(),
        ])->saveQuietly();

        $corps = ['criteres' => ['campagne' => $this->campagne->id], 'nombre_correspondants' => 2];

        // AVANT rétablissement : les 2 dossiers correspondent (non-brouillon),
        // mais seul le filler est affectable — le rejeté est ignoré (non éligible).
        $avant = $this->actingAs($this->admin)
            ->postJson('/api/admin/affectations/automatique/apercu', $corps)
            ->assertOk();
        $this->assertSame(1, $avant->json('data.nombre_affectables'));
        $this->assertSame(1, $avant->json('data.ignores.non_eligibles'));

        $this->retablir([$candidature->id], 'Attestation complémentaire reçue.')->assertOk();
        $this->assertSame('soumis', $candidature->fresh()->statut_interne);
        $this->assertSame('eligible', $candidature->fresh()->statut_eligibilite_interne);

        // APRÈS rétablissement : même nombre de correspondants (2), mais les
        // 2 sont désormais affectables — exactement le delta +1/-1 demandé.
        $apres = $this->actingAs($this->admin)
            ->postJson('/api/admin/affectations/automatique/apercu', $corps)
            ->assertOk();
        $this->assertSame(2, $apres->json('data.nombre_affectables'));
        $this->assertSame(0, $apres->json('data.ignores.non_eligibles'));

        // (a) Affectation MANUELLE réelle.
        $this->actingAs($this->admin)->postJson('/api/admin/affectations', [
            'evaluateur_id' => $this->evaluateur->membreEquipe->id,
            'candidature_ids' => [$candidature->id],
        ])->assertOk();

        $fraiche = $candidature->fresh();
        $this->assertSame('en_instruction', $fraiche->statut_interne);
        $this->assertSame($this->evaluateur->membreEquipe->id, $fraiche->evaluateur_id);

        // (c) L'évaluateur ouvre la fiche...
        $this->actingAs($this->evaluateur)->getJson("/api/evaluateur/candidatures/{$candidature->id}")
            ->assertOk()
            ->assertJsonPath('data.statut_eligibilite_interne', 'eligible');

        // ...et enregistre sa vérification.
        $this->actingAs($this->evaluateur)
            ->putJson("/api/evaluateur/candidatures/{$candidature->id}/verification", [
                'nationalite_confirmee' => true,
                'diplome_verifie' => 'bac',
            ])
            ->assertOk()
            ->assertJsonPath('data.statut_eligibilite_interne', 'eligible');
    }

    // --- Atomicité d'un lot mixte (revue Étape A, point 2). ---

    public function test_lot_mixte_non_eligible_et_deja_eligible_422_rien_ecrit(): void
    {
        // Ordre important (fragilité de fixture, pas produit) : `candidatureEvaluee`
        // numérote avec un compteur local qui ignore la base, alors que le VRAI
        // générateur (`GenerateurNumeroDossier`, utilisé par `candidatureElimineeEnInstruction`
        // via /api/candidatures) interroge toujours le numéro MAX existant — donc
        // seul CET ordre évite une collision sur `numero_dossier`.
        $dejaEligible = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $nonEligible = $this->candidatureElimineeEnInstruction();

        $this->retablir([$nonEligible->id, $dejaEligible->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('candidature_ids');

        $fraiche = $nonEligible->fresh();
        $this->assertSame('non_eligible', $fraiche->statut_eligibilite_interne);
        $this->assertFalse($fraiche->eligibilite_retablie);
        $this->assertSame(0, JournalAudit::where('action', "Rétablissement d'éligibilité")->count());
        $this->assertFalse($this->campagne->fresh()->classement_perime);
    }

    // --- Brouillon (revue Étape A, point 3 — message corrigé). ---

    public function test_brouillon_422_avec_un_message_qui_ne_pretend_pas_qu_il_est_deja_eligible(): void
    {
        $candidat = $this->creerCandidat('brouillon-lot4@casa-demo.ci');
        $id = $this->actingAs($candidat)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');
        $brouillon = Candidature::findOrFail($id);
        $this->assertSame('non_verifie', $brouillon->statut_eligibilite_interne);

        $reponse = $this->retablir([$brouillon->id])->assertStatus(422)->assertJsonValidationErrors('candidature_ids');

        $message = $reponse->json('errors.candidature_ids.0');
        $this->assertStringNotContainsString('Déjà éligible', $message);
        $this->assertStringContainsStringIgnoringCase("n'est pas non éligible", $message);
    }

    // --- Validations manquantes (revue Étape A, point 4). ---

    public function test_uuid_inconnu_422(): void
    {
        $candidature = $this->candidatureElimineeEnInstruction();

        $reponse = $this->retablir([$candidature->id, (string) Str::uuid()])->assertStatus(422);
        $this->assertTrue(
            collect(array_keys($reponse->json('errors')))->contains(fn ($cle) => str_starts_with($cle, 'candidature_ids')),
        );
    }

    public function test_ids_en_double_422(): void
    {
        $candidature = $this->candidatureElimineeEnInstruction();

        $reponse = $this->retablir([$candidature->id, $candidature->id])->assertStatus(422);
        $this->assertTrue(
            collect(array_keys($reponse->json('errors')))->contains(fn ($cle) => str_starts_with($cle, 'candidature_ids')),
        );
        $this->assertSame('non_eligible', $candidature->fresh()->statut_eligibilite_interne);
    }

    public function test_succes_sur_plusieurs_dossiers_une_ligne_d_audit_chacun_sans_donnee_personnelle(): void
    {
        $fixtures = [
            ['email' => 'aminata-pii@casa-demo.ci', 'prenom' => 'Aminata', 'nom' => 'Kone', 'cni' => 'CI999000001'],
            ['email' => 'boubacar-pii@casa-demo.ci', 'prenom' => 'Boubacar', 'nom' => 'Traore', 'cni' => 'CI999000002'],
            ['email' => 'coralie-pii@casa-demo.ci', 'prenom' => 'Coralie', 'nom' => 'Diabate', 'cni' => 'CI999000003'],
        ];

        $candidatures = [];
        foreach ($fixtures as $f) {
            $candidat = $this->creerCandidat($f['email'], ['prenom' => $f['prenom'], 'nom' => $f['nom'], 'cni' => $f['cni']]);
            // Preuve que `creerCandidat` applique bien le `profil` demandé (et
            // ne retombe pas sur ses valeurs par défaut 'Test'/'Candidat') —
            // sinon le test de non-fuite ci-dessous passerait à vide.
            $this->assertSame($f['prenom'], $candidat->candidat->prenom);
            $this->assertSame($f['nom'], $candidat->candidat->nom);
            $this->assertSame($f['cni'], $candidat->candidat->cni);
            $c = $this->candidatureAffectee($candidat, $this->evaluateur);
            $this->actingAs($this->admin)->postJson("/api/admin/candidatures/{$c->id}/elimination", [
                'motif' => 'Test — pièce suspecte.',
            ])->assertOk();
            $candidatures[] = $c->fresh();
        }

        $ids = array_map(fn (Candidature $c) => $c->id, $candidatures);
        $this->retablir($ids)->assertOk()->assertJsonPath('data.retablies', 3);

        $lignes = JournalAudit::where('action', "Rétablissement d'éligibilité")->get();
        $this->assertCount(3, $lignes);

        foreach ($lignes as $ligne) {
            foreach ($fixtures as $f) {
                foreach ([$f['email'], $f['prenom'], $f['nom'], $f['cni']] as $motInterdit) {
                    $this->assertStringNotContainsString($motInterdit, (string) $ligne->objet);
                    $this->assertStringNotContainsString($motInterdit, (string) $ligne->ancienne_valeur);
                    $this->assertStringNotContainsString($motInterdit, (string) $ligne->nouvelle_valeur);
                    $this->assertStringNotContainsString($motInterdit, (string) $ligne->motif);
                }
            }
        }
    }
}
