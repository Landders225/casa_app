<?php

namespace Tests\Feature\Evaluateur;

use App\Models\Candidature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Flux de notation du dossier (Lot 4b) : aperçu recalculé → brouillon
 * (MO.04 + commentaire) → validation définitive → snapshot figé + verrouillage.
 */
class NotationDossierTest extends TestCase
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
        $this->seedBareme();
        $this->evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
        $this->candidature = $this->candidatureAffectee(
            $this->creerCandidat('cand@casa-demo.ci'),
            $this->evaluateur,
        );
    }

    private function url(string $suffixe = ''): string
    {
        return "/api/evaluateur/candidatures/{$this->candidature->id}/evaluation{$suffixe}";
    }

    public function test_apercu_recalcule_a_la_volee_sans_rien_persister(): void
    {
        $this->actingAs($this->evaluateur)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.source', 'apercu')
            ->assertJsonPath('data.verrouille', false)
            ->assertJsonPath('data.grille.version', 1)
            ->assertJsonCount(6, 'data.rubriques');

        $this->assertDatabaseMissing('evaluation_dossier', ['candidature_id' => $this->candidature->id]);
    }

    public function test_brouillon_enregistre_la_note_MO04_et_le_commentaire(): void
    {
        $this->actingAs($this->evaluateur)->putJson($this->url(), [
            'mo04_note_etoiles' => 4,
            'commentaire_evaluateur' => 'Lettre convaincante, projet cohérent.',
        ])->assertOk()
            ->assertJsonPath('data.mo04_note_etoiles', 4)
            ->assertJsonPath('data.commentaire_evaluateur', 'Lettre convaincante, projet cohérent.')
            ->assertJsonPath('data.source', 'apercu');

        $this->assertDatabaseHas('reponse_formulaire', [
            'candidature_id' => $this->candidature->id,
            'mo04_note_etoiles' => 4,
        ]);
        $this->assertDatabaseHas('candidature', [
            'id' => $this->candidature->id,
            'commentaire_evaluateur' => 'Lettre convaincante, projet cohérent.',
        ]);
    }

    public function test_note_MO04_hors_bornes_rejetee_422(): void
    {
        $this->actingAs($this->evaluateur)->putJson($this->url(), ['mo04_note_etoiles' => 7])
            ->assertStatus(422)->assertJsonValidationErrors('mo04_note_etoiles');
    }

    public function test_validation_impossible_si_verification_4a_incomplete_422(): void
    {
        $this->actingAs($this->evaluateur)->putJson($this->url(), ['mo04_note_etoiles' => 4])->assertOk();

        // Aucune vérification 4a posée.
        $this->actingAs($this->evaluateur)->postJson($this->url('/validation'))
            ->assertStatus(422);

        // Nationalité confirmée mais diplôme absent : toujours bloqué.
        $this->poserVerification($this->candidature, diplome: null, nationalite: true);
        $this->actingAs($this->evaluateur)->postJson($this->url('/validation'))
            ->assertStatus(422);
    }

    public function test_validation_impossible_sans_note_MO04_422(): void
    {
        $this->poserVerification($this->candidature, 'bac', true);

        $this->actingAs($this->evaluateur)->postJson($this->url('/validation'))
            ->assertStatus(422);
    }

    public function test_validation_definitive_fige_le_snapshot_et_verrouille(): void
    {
        $this->poserVerification($this->candidature, 'bac', true);
        $this->actingAs($this->evaluateur)->putJson($this->url(), ['mo04_note_etoiles' => 4])->assertOk();

        $this->actingAs($this->evaluateur)->postJson($this->url('/validation'))
            ->assertOk()
            ->assertJsonPath('data.source', 'snapshot')
            ->assertJsonPath('data.verrouille', true)
            // réponses éligibles + SC.04 bac + MO.04 4 étoiles -> 45,0 (calcul manuel)
            ->assertJsonPath('data.score_total', '45.0')
            ->assertJsonPath('data.valide_par.prenom', 'Eval')
            ->assertJsonCount(6, 'data.rubriques');

        $this->assertDatabaseHas('evaluation_dossier', [
            'candidature_id' => $this->candidature->id,
            'score_total' => '45.0',
            'valide' => true,
            'valide_par' => $this->evaluateur->membreEquipe->id,
        ]);
        $this->assertSame(6, DB::table('score_rubrique_dossier')
            ->where('evaluation_dossier_id', $this->candidature->id)->count());

        $c = $this->candidature->fresh();
        $this->assertTrue((bool) $c->dossier_verrouille);
        $this->assertSame('evalue', $c->statut_interne);
        $this->assertNotNull($c->date_evaluation);
        $this->assertSame($this->evaluateur->membreEquipe->id, $c->dossier_verrouille_par);

        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $this->evaluateur->id,
            'action' => "Validation d'évaluation",
            'module' => 'Évaluation',
            'nouvelle_valeur' => '45.0/65',
        ]);
    }

    public function test_le_detail_par_rubrique_est_stocke_avec_4_decimales(): void
    {
        // Langues raw9 = 6 -> 6/9*10 = 6,6667 : la précision numeric(6,4) (D-4b-1).
        $this->candidature->reponseFormulaire->forceFill([
            'langue_ecrit' => 3, 'langue_parle' => 3, 'langue_comprehension' => 3,
            'info_word' => 0, 'info_excel' => 0, 'info_internet' => 0,
        ])->save();
        $this->poserVerification($this->candidature, 'bac', true);
        $this->actingAs($this->evaluateur)->putJson($this->url(), ['mo04_note_etoiles' => 3])->assertOk();

        $this->actingAs($this->evaluateur)->postJson($this->url('/validation'))->assertOk();

        $rubriqueLanguesId = DB::table('rubrique')->where('code', 'langues')->value('id');
        $this->assertSame('6.6667', DB::table('score_rubrique_dossier')
            ->where('evaluation_dossier_id', $this->candidature->id)
            ->where('rubrique_id', $rubriqueLanguesId)
            ->value('score_obtenu'));
    }

    public function test_un_dossier_non_eligible_peut_quand_meme_etre_note(): void
    {
        // Q4 : le score est un fait ; le verdict d'éligibilité vit ailleurs.
        $this->candidature->forceFill(['statut_eligibilite_interne' => 'non_eligible'])->saveQuietly();
        $this->poserVerification($this->candidature, 'bac', true);
        $this->actingAs($this->evaluateur)->putJson($this->url(), ['mo04_note_etoiles' => 2])->assertOk();

        $this->actingAs($this->evaluateur)->postJson($this->url('/validation'))->assertOk()
            ->assertJsonPath('data.verrouille', true);

        $c = $this->candidature->fresh();
        $this->assertSame('evalue', $c->statut_interne);
        // statut_eligibilite_interne NON touché par 4b (D-4b-3).
        $this->assertSame('non_eligible', $c->statut_eligibilite_interne);
    }

    public function test_isolation_evaluateur_non_affecte_404(): void
    {
        $autre = $this->creerEvaluateur('autre@casa-demo.ci');

        $this->actingAs($autre)->getJson($this->url())->assertStatus(404);
        $this->actingAs($autre)->putJson($this->url(), ['mo04_note_etoiles' => 3])->assertStatus(404);
        $this->actingAs($autre)->postJson($this->url('/validation'))->assertStatus(404);
    }

    public function test_admin_peut_noter_un_dossier_non_affecte_a_lui(): void
    {
        $admin = $this->creerAdmin('admin@casa-demo.ci');
        $this->poserVerification($this->candidature, 'bac', true);

        $this->actingAs($admin)->putJson($this->url(), ['mo04_note_etoiles' => 5])->assertOk();
        $this->actingAs($admin)->postJson($this->url('/validation'))->assertOk()
            ->assertJsonPath('data.verrouille', true);
    }

    public function test_le_candidat_ne_peut_pas_appeler_les_routes_de_notation(): void
    {
        $candidat = $this->creerCandidat('autre-cand@casa-demo.ci');

        $this->actingAs($candidat)->getJson($this->url())->assertStatus(403);
        $this->actingAs($candidat)->putJson($this->url(), ['mo04_note_etoiles' => 3])->assertStatus(403);
        $this->actingAs($candidat)->postJson($this->url('/validation'))->assertStatus(403);
    }
}
