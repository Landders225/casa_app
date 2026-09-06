<?php

namespace Tests\Feature\Admin;

use App\Models\Campagne;
use App\Models\Candidature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;
use Tests\TestCase;

/**
 * Correction exceptionnelle (Lot 6b, ADR-15) — SEULE exception au verrouillage
 * ADR-04. Motif obligatoire, nouveau snapshot serveur, relance d'éligibilité,
 * audit ancien→nouveau lisible. Pré-publication uniquement (409 sinon).
 */
class CorrectionExceptionnelleTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private Campagne $campagne;

    private User $admin;

    private User $evaluateur;

    private User $candidatUser;

    private Candidature $candidature;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->seedBareme();
        $this->campagne = Campagne::ouverte()->firstOrFail();
        $this->admin = $this->creerAdmin('admin@casa-demo.ci');
        $this->evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
        $this->candidatUser = $this->creerCandidat('cand@casa-demo.ci');

        $this->candidature = $this->candidatureAffectee($this->candidatUser, $this->evaluateur);
        $this->poserVerification($this->candidature, 'bac', true);
        $this->verrouillerNotationReelle(4); // snapshot figé à 45,0 (calcul Lot 4b)
    }

    /** Verrouille le dossier via le VRAI parcours évaluateur (snapshot + rubriques). */
    private function verrouillerNotationReelle(int $mo04): void
    {
        $base = "/api/evaluateur/candidatures/{$this->candidature->id}/evaluation";
        $this->actingAs($this->evaluateur)->putJson($base, ['mo04_note_etoiles' => $mo04])->assertOk();
        $this->actingAs($this->evaluateur)->postJson($base.'/validation')->assertOk();
        $this->candidature = $this->candidature->fresh();
    }

    private function urlDossier(): string
    {
        return "/api/admin/candidatures/{$this->candidature->id}/correction/dossier";
    }

    private function urlEntretien(): string
    {
        return "/api/admin/candidatures/{$this->candidature->id}/correction/entretien";
    }

    private function publier(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/classement")->assertOk();
        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/publier")->assertOk();
    }

    public function test_correction_dossier_rouvre_le_verrou_et_reproduit_un_snapshot(): void
    {
        $this->assertDatabaseHas('evaluation_dossier', [
            'candidature_id' => $this->candidature->id,
            'score_total' => '45.0',
        ]);

        $this->actingAs($this->admin)->postJson($this->urlDossier(), [
            'motif' => 'Note MO.04 saisie par erreur : la lettre de motivation vaut 2 étoiles.',
            'reponses' => ['mo04_note_etoiles' => 2],
        ])->assertOk()
            ->assertJsonPath('data.score_dossier', '39.0') // 45,0 - (4-2)/5*15
            ->assertJsonPath('data.champs_modifies', ['mo04_note_etoiles']);

        // Le snapshot est ÉCRASÉ (MLD 1-1), pas empilé.
        $this->assertDatabaseCount('evaluation_dossier', 1);
        $this->assertDatabaseHas('evaluation_dossier', [
            'candidature_id' => $this->candidature->id,
            'score_total' => '39.0',
        ]);
        $this->assertSame(6, DB::table('score_rubrique_dossier')
            ->where('evaluation_dossier_id', $this->candidature->id)->count());
    }

    public function test_l_audit_capture_l_ancienne_et_la_nouvelle_valeur_avec_le_motif(): void
    {
        $this->actingAs($this->admin)->postJson($this->urlDossier(), [
            'motif' => 'Correction MO.04 sur pièce.',
            'reponses' => ['mo04_note_etoiles' => 2],
        ])->assertOk();

        $ligne = DB::table('journal_audit')
            ->where('action', 'Correction exceptionnelle — évaluation dossier')
            ->where('objet', $this->candidature->numero_dossier)
            ->first();

        $this->assertNotNull($ligne);
        $this->assertSame('Correction MO.04 sur pièce.', $ligne->motif);
        $this->assertStringContainsString('score dossier 45.0/65', $ligne->ancienne_valeur);
        $this->assertStringContainsString('mo04_note_etoiles: 4', $ligne->ancienne_valeur);
        $this->assertStringContainsString('score dossier 39.0/65', $ligne->nouvelle_valeur);
        $this->assertStringContainsString('mo04_note_etoiles: 2', $ligne->nouvelle_valeur);
    }

    public function test_correction_sans_motif_422(): void
    {
        $this->actingAs($this->admin)->postJson($this->urlDossier(), [
            'reponses' => ['mo04_note_etoiles' => 2],
        ])->assertStatus(422)->assertJsonValidationErrors('motif');

        $this->assertDatabaseHas('evaluation_dossier', [
            'candidature_id' => $this->candidature->id,
            'score_total' => '45.0',
        ]);
    }

    public function test_correction_relance_l_eligibilite_et_retrace_les_criteres(): void
    {
        $this->assertSame('eligible', $this->candidature->fresh()->statut_eligibilite_interne);

        $this->actingAs($this->admin)->postJson($this->urlDossier(), [
            'motif' => "Vérification : le candidat n'est pas disponible en semaine (attestation employeur).",
            'reponses' => ['di01_disponible_lun_ven' => 'non'],
        ])->assertOk()
            ->assertJsonPath('data.statut_eligibilite_interne', 'non_eligible');

        $this->assertDatabaseHas('critere_eliminatoire_declenche', [
            'candidature_id' => $this->candidature->id,
            'code_critere' => 'DI.01',
            'origine' => 'soumission_candidat',
        ]);

        $ligne = DB::table('journal_audit')
            ->where('action', 'Correction exceptionnelle — évaluation dossier')->first();
        $this->assertStringContainsString('éligibilité: eligible', $ligne->ancienne_valeur);
        $this->assertStringContainsString('éligibilité: non_eligible', $ligne->nouvelle_valeur);
    }

    public function test_correction_impossible_apres_publication_409(): void
    {
        // Rendre la candidature classable (entretien figé) puis publier.
        $this->candidature = $this->verrouillerEntretien($this->candidature, '20.0', 'present');
        $this->publier();

        $this->actingAs($this->admin)->postJson($this->urlDossier(), [
            'motif' => 'Trop tard : les décisions sont communiquées.',
            'reponses' => ['mo04_note_etoiles' => 2],
        ])->assertStatus(409);

        $this->assertDatabaseHas('evaluation_dossier', [
            'candidature_id' => $this->candidature->id,
            'score_total' => '45.0',
        ]);
    }

    public function test_correction_dossier_impossible_si_non_verrouille_409(): void
    {
        $autreCandidat = $this->creerCandidat('cand2@casa-demo.ci');
        $ouverte = $this->candidatureAffectee($autreCandidat, $this->evaluateur);
        $this->poserVerification($ouverte, 'bac', true);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/candidatures/{$ouverte->id}/correction/dossier", [
                'motif' => 'Le dossier n’est pas encore verrouillé.',
                'reponses' => ['mo04_note_etoiles' => 2],
            ])->assertStatus(409);
    }

    public function test_correction_entretien_recalcule_le_snapshot(): void
    {
        $this->candidature = $this->verrouillerEntretien($this->candidature, '0.0', 'present');

        $this->actingAs($this->admin)->postJson($this->urlEntretien(), [
            'motif' => 'Sous-notes de présentation non reportées dans l’outil.',
            'notes' => ['PRES.01' => 3, 'PRES.02' => 2, 'PRES.03' => 3], // rubrique présentation pleine (8/8 -> 8)
        ])->assertOk()
            ->assertJsonPath('data.score_entretien', '8.0');

        $this->assertDatabaseHas('entretien', [
            'candidature_id' => $this->candidature->id,
            'score_total' => '8.0',
            'statut' => 'valide',
        ]);

        $ligne = DB::table('journal_audit')
            ->where('action', 'Correction exceptionnelle — entretien')->first();
        $this->assertNotNull($ligne);
        $this->assertStringContainsString('score entretien 0.0/35', $ligne->ancienne_valeur);
        $this->assertStringContainsString('score entretien 8.0/35', $ligne->nouvelle_valeur);
        $this->assertStringContainsString('PRES.01: 3', $ligne->nouvelle_valeur);
    }

    public function test_correction_entretien_sans_motif_422(): void
    {
        $this->candidature = $this->verrouillerEntretien($this->candidature, '0.0', 'present');

        $this->actingAs($this->admin)->postJson($this->urlEntretien(), [
            'notes' => ['PRES.01' => 3],
        ])->assertStatus(422)->assertJsonValidationErrors('motif');
    }

    public function test_correction_entretien_note_hors_bornes_422(): void
    {
        $this->candidature = $this->verrouillerEntretien($this->candidature, '0.0', 'present');

        $this->actingAs($this->admin)->postJson($this->urlEntretien(), [
            'motif' => 'Saisie erronée.',
            'notes' => ['PRES.01' => 5], // max 3
        ])->assertStatus(422);
    }

    public function test_correction_reservee_a_l_administrateur(): void
    {
        foreach ([$this->evaluateur, $this->candidatUser] as $intrus) {
            $this->actingAs($intrus)->postJson($this->urlDossier(), [
                'motif' => 'Tentative.',
                'reponses' => ['mo04_note_etoiles' => 1],
            ])->assertForbidden();
        }

        $this->assertDatabaseHas('evaluation_dossier', [
            'candidature_id' => $this->candidature->id,
            'score_total' => '45.0',
        ]);
    }

    public function test_la_correction_ne_fuit_pas_vers_le_candidat(): void
    {
        $avant = $this->actingAs($this->candidatUser)->getJson('/api/candidature');
        $avant->assertOk();

        $this->actingAs($this->admin)->postJson($this->urlDossier(), [
            'motif' => 'Correction interne.',
            'reponses' => ['mo04_note_etoiles' => 0],
            'commentaire_evaluateur' => 'Dossier revu par la coordination.',
        ])->assertOk();

        $apres = $this->actingAs($this->candidatUser)->getJson('/api/candidature');
        $apres->assertOk();
        $this->assertSame($avant->getContent(), $apres->getContent());
    }
}
