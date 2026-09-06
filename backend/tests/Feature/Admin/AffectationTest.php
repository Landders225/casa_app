<?php

namespace Tests\Feature\Admin;

use App\Models\Candidature;
use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;
use Tests\TestCase;

/**
 * Affectation d'un évaluateur (Lot 6a) — résout D-4a-1 : le 4a devient testable
 * via le VRAI endpoint, plus seulement le seeder/trait.
 */
class AffectationTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private User $admin;

    private User $evaluateur;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->seedBareme();
        $this->admin = $this->creerAdmin('admin@casa-demo.ci');
        $this->evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
    }

    private function candidatureSoumise(string $filiere = 'cuisine'): Candidature
    {
        $candidat = $this->creerCandidat();
        $id = $this->actingAs($candidat)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere($filiere)])
            ->json('data.id');
        $candidature = Candidature::findOrFail($id);
        $this->rendreCandidatureComplete($candidature);
        $this->actingAs($candidat)->postJson("/api/candidatures/{$id}/soumettre")->assertOk();

        return $candidature->fresh();
    }

    private function affecter(array $ids, ?string $evaluateurId = null): TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/admin/affectations', [
            'evaluateur_id' => $evaluateurId ?? $this->evaluateur->membreEquipe->id,
            'candidature_ids' => $ids,
        ]);
    }

    public function test_affectation_en_masse_pose_l_evaluateur_et_bascule_en_instruction(): void
    {
        $a = $this->candidatureSoumise();
        $b = $this->candidatureSoumise();

        $this->affecter([$a->id, $b->id])
            ->assertOk()
            ->assertJsonPath('data.affectees', 2)
            ->assertJsonPath('data.evaluateur.prenom', 'Eval');

        foreach ([$a, $b] as $candidature) {
            $fraiche = $candidature->fresh();
            $this->assertSame($this->evaluateur->membreEquipe->id, $fraiche->evaluateur_id);
            $this->assertSame('en_instruction', $fraiche->statut_interne);
        }
    }

    public function test_une_ligne_d_audit_par_candidature(): void
    {
        $a = $this->candidatureSoumise();
        $b = $this->candidatureSoumise();

        $this->affecter([$a->id, $b->id])->assertOk();

        $this->assertSame(2, JournalAudit::where('action', 'Affectation évaluateur')->count());
        $this->assertDatabaseHas('journal_audit', [
            'action' => 'Affectation évaluateur',
            'module' => 'Candidatures',
            'objet' => $a->numero_dossier,
            'ancienne_valeur' => null,
            'nouvelle_valeur' => 'Eval Test',
        ]);
    }

    public function test_atomicite_une_seule_non_affectable_bloque_tout_le_lot_422(): void
    {
        $ok = $this->candidatureSoumise();
        $brouillon = Candidature::findOrFail(
            $this->actingAs($this->creerCandidat())
                ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
                ->json('data.id'),
        );

        $this->affecter([$ok->id, $brouillon->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('candidature_ids');

        // Rien n'a été écrit.
        $this->assertNull($ok->fresh()->evaluateur_id);
        $this->assertSame('soumis', $ok->fresh()->statut_interne);
        $this->assertSame(0, JournalAudit::where('action', 'Affectation évaluateur')->count());
    }

    public function test_reaffectation_possible_tant_que_en_instruction(): void
    {
        $c = $this->candidatureSoumise();
        $eval2 = $this->creerEvaluateur('eval2@casa-demo.ci');

        $this->affecter([$c->id])->assertOk();
        $this->assertSame($this->evaluateur->membreEquipe->id, $c->fresh()->evaluateur_id);

        // Ré-affectation.
        $this->affecter([$c->id], $eval2->membreEquipe->id)->assertOk();
        $this->assertSame($eval2->membreEquipe->id, $c->fresh()->evaluateur_id);
        $this->assertSame('en_instruction', $c->fresh()->statut_interne);
    }

    public function test_la_cible_doit_etre_un_evaluateur_pas_un_admin_422(): void
    {
        $c = $this->candidatureSoumise();
        $autreAdmin = $this->creerAdmin('admin2@casa-demo.ci');

        $this->affecter([$c->id], $autreAdmin->membreEquipe->id)->assertStatus(422);
    }

    public function test_le_4a_fonctionne_via_le_vrai_endpoint_d_affectation(): void
    {
        $c = $this->candidatureSoumise();

        // Affectation par l'admin (vrai endpoint, plus le seeder).
        $this->affecter([$c->id])->assertOk();

        // L'évaluateur affecté accède à la fiche et pose la vérification (Lot 4a).
        $this->actingAs($this->evaluateur)
            ->getJson("/api/evaluateur/candidatures/{$c->id}")
            ->assertOk()
            ->assertJsonPath('data.statut_interne', 'en_instruction');

        $this->actingAs($this->evaluateur)
            ->putJson("/api/evaluateur/candidatures/{$c->id}/verification", [
                'nationalite_confirmee' => true, 'diplome_verifie' => 'bac',
            ])->assertOk()
            ->assertJsonPath('data.statut_eligibilite_interne', 'eligible');

        // Un évaluateur NON affecté ne voit rien (404).
        $eval2 = $this->creerEvaluateur('eval2@casa-demo.ci');
        $this->actingAs($eval2)->getJson("/api/evaluateur/candidatures/{$c->id}")->assertStatus(404);
    }

    public function test_l_affectation_ne_revele_pas_l_evaluateur_au_candidat(): void
    {
        $candidat = $this->creerCandidat('cand@casa-demo.ci');
        $id = $this->actingAs($candidat)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');
        $candidature = Candidature::findOrFail($id);
        $this->rendreCandidatureComplete($candidature);
        $this->actingAs($candidat)->postJson("/api/candidatures/{$id}/soumettre")->assertOk();

        $avant = $this->actingAs($candidat)->getJson('/api/candidature')->getContent();

        $this->affecter([$id])->assertOk();

        $apres = $this->actingAs($candidat)->getJson('/api/candidature');
        $apres->assertOk();
        $this->assertSame($avant, $apres->getContent());

        foreach (['evaluateur', 'Eval Test', 'en_instruction', 'statut_interne'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $apres->getContent());
        }
    }

    public function test_affectation_admin_only(): void
    {
        $c = $this->candidatureSoumise();
        $candidat = $this->creerCandidat('c@casa-demo.ci');

        foreach ([$this->evaluateur, $candidat] as $intrus) {
            $this->actingAs($intrus)->postJson('/api/admin/affectations', [
                'evaluateur_id' => $this->evaluateur->membreEquipe->id,
                'candidature_ids' => [$c->id],
            ])->assertStatus(403);
        }
    }
}
