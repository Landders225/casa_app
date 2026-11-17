<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Api\Admin\ReattributionController;
use App\Models\Candidature;
use App\Models\JournalAudit;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;
use Tests\TestCase;

/**
 * Réattribution de dossiers DÉJÀ AFFECTÉS, vers un autre évaluateur ou vers
 * le lot non affecté (Lot 20b, ADR-47) — une règle par état de l'évaluation.
 */
class ReattributionTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private User $admin;

    private User $evaluateur;

    private User $evaluateur2;

    /** @var array<string,int> Même fixture que `NotationEntretienTest` (scoring.js -> 33,0). */
    private array $notesFort = [
        'PRES.01' => 3, 'PRES.02' => 2, 'PRES.03' => 3,
        'REL.01' => 3, 'REL.02' => 2, 'REL.03' => 4,
        'EO.01' => 3, 'EO.02' => 3, 'EO.03' => 2,
        'MOE.01' => 3, 'MOE.02' => 3, 'MOE.03' => 2,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->seedBareme();
        $this->admin = $this->creerAdmin('admin@casa-demo.ci');
        $this->evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
        // Correctif de revue : nom clairement DISTINCT (pas un générique
        // « Eval Test » partagé) — prouve sans ambiguïté le SENS ancien ->
        // nouveau dans les lignes d'audit, plutôt que de s'appuyer sur deux
        // id identiques en apparence.
        $this->evaluateur2 = $this->creerEvaluateur('eval2@casa-demo.ci', 'Fatou', 'Koné');
    }

    private function reattribuer(array $ids, ?string $evaluateurId): TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/admin/reattributions', [
            'evaluateur_id' => $evaluateurId,
            'candidature_ids' => $ids,
        ]);
    }

    private function planifierEntretien(Candidature $candidature, User $evaluateur): void
    {
        $this->actingAs($evaluateur)->putJson("/api/evaluateur/candidatures/{$candidature->id}/entretien", [
            'date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Le Plateau',
        ])->assertOk();
    }

    // --- Cible identique à l'évaluateur déjà en place : refusée AVANT tout ---
    // --- examen du brouillon (correctif de revue). ---

    public function test_cible_identique_a_l_evaluateur_actuel_refusee_sans_reinitialiser_le_brouillon(): void
    {
        $c = $this->candidatureAffectee($this->creerCandidat('a@casa-demo.ci'), $this->evaluateur);
        $this->actingAs($this->evaluateur)->putJson("/api/evaluateur/candidatures/{$c->id}/evaluation", [
            'mo04_note_etoiles' => 4,
            'commentaire_evaluateur' => 'Brouillon qui doit survivre intact',
        ])->assertOk();

        $reponse = $this->reattribuer([$c->id], $this->evaluateur->membreEquipe->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('candidature_ids');
        $this->assertStringContainsString('Déjà affecté', $reponse->json('errors.candidature_ids.0'));

        // Le brouillon n'a PAS été touché (le refus intervient avant toute réinitialisation).
        $this->assertSame(4, (int) DB::table('reponse_formulaire')->where('candidature_id', $c->id)->value('mo04_note_etoiles'));
        $this->assertSame('Brouillon qui doit survivre intact', $c->fresh()->commentaire_evaluateur);
        $this->assertSame($this->evaluateur->membreEquipe->id, $c->fresh()->evaluateur_id);

        // Aucune ligne d'audit.
        $this->assertSame(0, JournalAudit::where('action', 'Réattribution évaluateur')->count());
    }

    // --- Dossier non éligible : refusée, jamais forcée vers « soumis ». ---

    public function test_dossier_non_eligible_refuse_la_reattribution_et_ne_force_jamais_soumis(): void
    {
        $c = $this->candidatureAffectee($this->creerCandidat('a@casa-demo.ci'), $this->evaluateur);
        $this->actingAs($this->admin)
            ->postJson("/api/admin/candidatures/{$c->id}/elimination", ['motif' => 'Motif de test'])
            ->assertOk();
        $this->assertSame('non_eligible', $c->fresh()->statut_eligibilite_interne);
        $this->assertSame('en_instruction', $c->fresh()->statut_interne);

        $reponse = $this->reattribuer([$c->id], $this->evaluateur2->membreEquipe->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('candidature_ids');
        $this->assertStringContainsString('éligible', $reponse->json('errors.candidature_ids.0'));

        $this->assertSame($this->evaluateur->membreEquipe->id, $c->fresh()->evaluateur_id);
        $this->assertSame('en_instruction', $c->fresh()->statut_interne);
        $this->assertSame(0, JournalAudit::where('action', 'Réattribution évaluateur')->count());
    }

    public function test_dossier_non_eligible_refuse_meme_le_retour_au_pool_non_affecte(): void
    {
        $c = $this->candidatureAffectee($this->creerCandidat('a@casa-demo.ci'), $this->evaluateur);
        $this->actingAs($this->admin)
            ->postJson("/api/admin/candidatures/{$c->id}/elimination", ['motif' => 'Motif de test'])
            ->assertOk();

        $this->reattribuer([$c->id], null)->assertStatus(422);

        // Jamais repoussé vers `soumis` : le résultat de l'élimination n'est pas effacé.
        $this->assertSame($this->evaluateur->membreEquipe->id, $c->fresh()->evaluateur_id);
        $this->assertNotSame('soumis', $c->fresh()->statut_interne);
    }

    // --- Absente : réattribution libre. ---

    public function test_dossier_sans_brouillon_se_reattribue_sans_effet_de_bord(): void
    {
        $c = $this->candidatureAffectee($this->creerCandidat('a@casa-demo.ci'), $this->evaluateur);

        $this->reattribuer([$c->id], $this->evaluateur2->membreEquipe->id)->assertOk();

        $fraiche = $c->fresh();
        $this->assertSame($this->evaluateur2->membreEquipe->id, $fraiche->evaluateur_id);
        $this->assertSame('en_instruction', $fraiche->statut_interne);

        $ligne = JournalAudit::where('action', 'Réattribution évaluateur')->first();
        $this->assertNotNull($ligne);
        $this->assertSame('Eval Test', $ligne->ancienne_valeur);
        $this->assertSame('Fatou Koné', $ligne->nouvelle_valeur);
        $this->assertNull($ligne->motif);
    }

    public function test_retour_au_pool_non_affecte_si_non_verrouille(): void
    {
        $c = $this->candidatureAffectee($this->creerCandidat('a@casa-demo.ci'), $this->evaluateur);

        $this->reattribuer([$c->id], null)->assertOk()->assertJsonPath('data.evaluateur', null);

        $fraiche = $c->fresh();
        $this->assertNull($fraiche->evaluateur_id);
        $this->assertSame('soumis', $fraiche->statut_interne);
    }

    // --- Commencée : brouillon réinitialisé, jamais copié dans l'audit. ---

    public function test_brouillon_dossier_commence_est_reinitialise_sans_etre_copie_dans_l_audit(): void
    {
        $c = $this->candidatureAffectee($this->creerCandidat('a@casa-demo.ci'), $this->evaluateur);
        $this->actingAs($this->evaluateur)->putJson("/api/evaluateur/candidatures/{$c->id}/evaluation", [
            'mo04_note_etoiles' => 4,
            'commentaire_evaluateur' => 'Commentaire interne sensible du premier évaluateur',
        ])->assertOk();

        $this->reattribuer([$c->id], $this->evaluateur2->membreEquipe->id)->assertOk();

        $this->assertNull(DB::table('reponse_formulaire')->where('candidature_id', $c->id)->value('mo04_note_etoiles'));
        $this->assertNull($c->fresh()->commentaire_evaluateur);

        $ligne = JournalAudit::where('action', 'Réattribution évaluateur')->where('objet', $c->numero_dossier)->first();
        $this->assertStringContainsString('réinitialisé', $ligne->motif);
        // JAMAIS le contenu effacé, nulle part dans la ligne.
        foreach ([$ligne->ancienne_valeur, $ligne->nouvelle_valeur, $ligne->motif] as $champ) {
            $this->assertStringNotContainsString('Commentaire interne sensible', (string) $champ);
        }
    }

    public function test_entretien_brouillon_planifie_est_supprime_a_la_reattribution(): void
    {
        $c = $this->verrouillerDossier($this->candidatureAffectee($this->creerCandidat('a@casa-demo.ci'), $this->evaluateur));
        $this->planifierEntretien($c, $this->evaluateur);
        $this->assertDatabaseHas('entretien', ['candidature_id' => $c->id, 'statut' => 'planifie']);

        $this->reattribuer([$c->id], $this->evaluateur2->membreEquipe->id)->assertOk();

        $this->assertDatabaseMissing('entretien', ['candidature_id' => $c->id]);
        $ligne = JournalAudit::where('action', 'Réattribution évaluateur')->where('objet', $c->numero_dossier)->first();
        $this->assertStringContainsString('réinitialisé', $ligne->motif);
    }

    public function test_entretien_brouillon_realise_avec_sous_notes_est_supprime_sans_violation_de_cle_etrangere(): void
    {
        $c = $this->verrouillerDossier($this->candidatureAffectee($this->creerCandidat('a@casa-demo.ci'), $this->evaluateur));
        $this->planifierEntretien($c, $this->evaluateur);
        $this->actingAs($this->evaluateur)->putJson("/api/evaluateur/candidatures/{$c->id}/entretien", [
            'presence' => 'present',
            'notes' => $this->notesFort,
        ])->assertOk()->assertJsonPath('data.entretien.statut', 'realise');
        $this->assertSame(12, DB::table('note_sous_critere_entretien')->where('entretien_id', $c->id)->count());

        $this->reattribuer([$c->id], $this->evaluateur2->membreEquipe->id)->assertOk();

        $this->assertSame(0, DB::table('note_sous_critere_entretien')->where('entretien_id', $c->id)->count());
        $this->assertDatabaseMissing('entretien', ['candidature_id' => $c->id]);
    }

    /**
     * Correctif de revue : preuve de réinitialisation COMPLÈTE, pas
     * seulement MO.04 et le commentaire — remplit un sous-ensemble
     * représentatif de TOUT ce qu'un évaluateur peut écrire (brouillon
     * dossier /65 : les deux seuls champs qui existent côté évaluateur,
     * `EnregistrerEvaluationRequest` ; entretien : date/heure/lieu, présence,
     * observation ET les 12 sous-notes), et vérifie qu'un évaluateur sortant
     * ne laisse RIEN derrière lui — hormis ce qui est déjà VERROUILLÉ
     * (score /65, jamais retouché, ADR-04).
     */
    public function test_reinitialisation_efface_integralement_tout_ce_que_l_evaluateur_sortant_a_ecrit(): void
    {
        $c = $this->candidatureAffectee($this->creerCandidat('a@casa-demo.ci'), $this->evaluateur);
        DB::table('reponse_formulaire')->where('candidature_id', $c->id)->update(['mo04_note_etoiles' => 5]);
        $c->forceFill(['commentaire_evaluateur' => 'Commentaire déjà verrouillé avec le dossier'])->saveQuietly();
        $c = $this->verrouillerDossier($c->fresh(), '52.0');

        $this->planifierEntretien($c, $this->evaluateur);
        $this->actingAs($this->evaluateur)->putJson("/api/evaluateur/candidatures/{$c->id}/entretien", [
            'presence' => 'present',
            'observation' => 'Observation détaillée du premier évaluateur',
            'notes' => $this->notesFort,
        ])->assertOk()->assertJsonPath('data.entretien.statut', 'realise');
        $this->assertSame(12, DB::table('note_sous_critere_entretien')->where('entretien_id', $c->id)->count());

        $this->reattribuer([$c->id], $this->evaluateur2->membreEquipe->id)->assertOk();

        // L'entretien a intégralement disparu : date, heure, lieu, présence,
        // observation ET les 12 sous-notes — jamais seulement les sous-notes.
        $this->assertDatabaseMissing('entretien', ['candidature_id' => $c->id]);
        $this->assertSame(0, DB::table('note_sous_critere_entretien')->where('entretien_id', $c->id)->count());

        // Le dossier /65, lui, reste intact : déjà verrouillé avant la
        // réattribution, son score ne doit JAMAIS être retouché (ADR-04).
        $this->assertSame(5, (int) DB::table('reponse_formulaire')->where('candidature_id', $c->id)->value('mo04_note_etoiles'));
        $this->assertSame('Commentaire déjà verrouillé avec le dossier', $c->fresh()->commentaire_evaluateur);
        $this->assertSame('52.0', $c->fresh()->evaluationDossier->score_total);
    }

    // --- Dossier /65 verrouillé, entretien pas encore validé : autorisée, score /65 inchangé. ---

    public function test_dossier_verrouille_entretien_absent_seul_l_entretien_change_de_main(): void
    {
        $c = $this->verrouillerDossier($this->candidatureAffectee($this->creerCandidat('a@casa-demo.ci'), $this->evaluateur), '52.0');

        $this->reattribuer([$c->id], $this->evaluateur2->membreEquipe->id)->assertOk();

        $fraiche = $c->fresh();
        $this->assertSame($this->evaluateur2->membreEquipe->id, $fraiche->evaluateur_id);
        $this->assertTrue((bool) $fraiche->dossier_verrouille);
        $this->assertSame('evalue', $fraiche->statut_interne);
        $this->assertSame('52.0', $fraiche->evaluationDossier->score_total); // score /65 JAMAIS retouché

        $ligne = JournalAudit::where('action', 'Réattribution évaluateur')->where('objet', $c->numero_dossier)->first();
        $this->assertStringContainsString("l'entretien", $ligne->motif);
        $this->assertStringContainsString('inchangé', $ligne->motif);
    }

    public function test_retour_au_pool_refuse_si_dossier_deja_verrouille(): void
    {
        $c = $this->verrouillerDossier($this->candidatureAffectee($this->creerCandidat('a@casa-demo.ci'), $this->evaluateur));

        $this->reattribuer([$c->id], null)
            ->assertStatus(422)
            ->assertJsonValidationErrors('candidature_ids');

        $fraiche = $c->fresh();
        $this->assertSame($this->evaluateur->membreEquipe->id, $fraiche->evaluateur_id);
        $this->assertSame(0, JournalAudit::where('action', 'Réattribution évaluateur')->count());
    }

    // --- Totalement validée : refusée. ---

    public function test_dossier_et_entretien_valides_refuse_422(): void
    {
        $c = $this->verrouillerEntretien($this->verrouillerDossier(
            $this->candidatureAffectee($this->creerCandidat('a@casa-demo.ci'), $this->evaluateur),
        ));

        $reponse = $this->reattribuer([$c->id], $this->evaluateur2->membreEquipe->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('candidature_ids');
        $this->assertStringContainsString('correction exceptionnelle', $reponse->json('errors.candidature_ids.0'));

        $this->assertSame($this->evaluateur->membreEquipe->id, $c->fresh()->evaluateur_id);
        $this->assertSame(0, JournalAudit::where('action', 'Réattribution évaluateur')->count());
    }

    // --- Campagne publiée : refusée quel que soit l'état. ---

    public function test_campagne_publiee_409_rien_n_est_ecrit(): void
    {
        $c = $this->candidatureAffectee($this->creerCandidat('a@casa-demo.ci'), $this->evaluateur);
        Publication::create([
            'campagne_id' => $c->campagne_id,
            'publiee_le' => now(),
            'publiee_par' => $this->admin->membreEquipe->id,
        ]);

        $this->reattribuer([$c->id], $this->evaluateur2->membreEquipe->id)->assertStatus(409);

        $this->assertSame($this->evaluateur->membreEquipe->id, $c->fresh()->evaluateur_id);
        $this->assertSame(0, JournalAudit::where('action', 'Réattribution évaluateur')->count());
    }

    // --- Dossier non affecté : utiliser l'affectation initiale, pas la réattribution. ---

    public function test_dossier_non_affecte_refuse_422(): void
    {
        $candidat = $this->creerCandidat('b@casa-demo.ci');
        $id = $this->actingAs($candidat)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');
        $this->rendreCandidatureComplete(Candidature::findOrFail($id));
        $this->actingAs($candidat)->postJson("/api/candidatures/{$id}/soumettre")->assertOk();

        $reponse = $this->reattribuer([$id], $this->evaluateur2->membreEquipe->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('candidature_ids');
        $this->assertStringContainsString("l'affectation initiale", $reponse->json('errors.candidature_ids.0'));
    }

    // --- Atomicité : un seul refus bloque tout le lot. ---

    public function test_atomicite_un_seul_refuse_bloque_tout_le_lot(): void
    {
        $ok = $this->candidatureAffectee($this->creerCandidat('a@casa-demo.ci'), $this->evaluateur);
        $totalementValide = $this->verrouillerEntretien($this->verrouillerDossier(
            $this->candidatureAffectee($this->creerCandidat('b@casa-demo.ci'), $this->evaluateur),
        ));

        $this->reattribuer([$ok->id, $totalementValide->id], $this->evaluateur2->membreEquipe->id)
            ->assertStatus(422);

        // RIEN n'a été écrit, pas même pour le dossier par ailleurs valide.
        $this->assertSame($this->evaluateur->membreEquipe->id, $ok->fresh()->evaluateur_id);
        $this->assertSame(0, JournalAudit::where('action', 'Réattribution évaluateur')->count());
    }

    // --- Idempotence : rejouer la même réattribution laisse le même état ---
    // --- final — depuis le correctif « cible identique » (point 1), le rejeu ---
    // --- est désormais un refus EXPLICITE (422) plutôt qu'un succès silencieux, ---
    // --- mais l'état final reste identique dans les deux cas. ---

    public function test_rejouer_la_meme_reattribution_est_refusee_mais_laisse_le_meme_etat_final(): void
    {
        $c = $this->candidatureAffectee($this->creerCandidat('a@casa-demo.ci'), $this->evaluateur);

        $this->reattribuer([$c->id], $this->evaluateur2->membreEquipe->id)->assertOk();
        $this->reattribuer([$c->id], $this->evaluateur2->membreEquipe->id)->assertStatus(422);

        $this->assertSame($this->evaluateur2->membreEquipe->id, $c->fresh()->evaluateur_id);
        $this->assertSame('en_instruction', $c->fresh()->statut_interne);
    }

    // --- Plafond par opération. ---

    public function test_au_dela_du_plafond_422(): void
    {
        $ids = array_map(fn () => (string) Str::uuid(), range(1, ReattributionController::PLAFOND_DOSSIERS + 1));

        $this->reattribuer($ids, $this->evaluateur2->membreEquipe->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('candidature_ids');
    }

    // --- Cible invalide. ---

    public function test_evaluateur_desactive_refuse_comme_cible_422(): void
    {
        $c = $this->candidatureAffectee($this->creerCandidat('a@casa-demo.ci'), $this->evaluateur);
        $this->evaluateur2->forceFill(['actif' => false])->save();

        $this->reattribuer([$c->id], $this->evaluateur2->membreEquipe->id)->assertStatus(422);
        $this->assertSame($this->evaluateur->membreEquipe->id, $c->fresh()->evaluateur_id);
    }

    public function test_admin_only(): void
    {
        $c = $this->candidatureAffectee($this->creerCandidat('a@casa-demo.ci'), $this->evaluateur);
        $candidat = $this->creerCandidat('c@casa-demo.ci');

        foreach ([$this->evaluateur, $candidat] as $intrus) {
            $this->actingAs($intrus)->postJson('/api/admin/reattributions', [
                'evaluateur_id' => $this->evaluateur2->membreEquipe->id,
                'candidature_ids' => [$c->id],
            ])->assertStatus(403);
        }
    }

    // --- 1 ligne d'audit par dossier, ancien ET nouveau évaluateur. ---

    public function test_une_ligne_d_audit_par_dossier_avec_ancien_et_nouvel_evaluateur(): void
    {
        $a = $this->candidatureAffectee($this->creerCandidat('a@casa-demo.ci'), $this->evaluateur);
        $b = $this->candidatureAffectee($this->creerCandidat('b@casa-demo.ci'), $this->evaluateur);

        $this->reattribuer([$a->id, $b->id], $this->evaluateur2->membreEquipe->id)
            ->assertOk()
            ->assertJsonPath('data.reattribuees', 2);

        $this->assertSame(2, JournalAudit::where('action', 'Réattribution évaluateur')->count());
        $this->assertDatabaseHas('journal_audit', [
            'action' => 'Réattribution évaluateur',
            'module' => 'Candidatures',
            'objet' => $a->numero_dossier,
            'ancienne_valeur' => 'Eval Test',
            'nouvelle_valeur' => 'Fatou Koné',
        ]);
    }
}
