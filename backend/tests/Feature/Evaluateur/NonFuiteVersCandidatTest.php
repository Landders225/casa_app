<?php

namespace Tests\Feature\Evaluateur;

use App\Models\Candidature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * C.3 (règle reine du Lot 4a) : le candidat ne voit RIEN de la vérification.
 *
 * Exerce le VRAI chemin candidat (CandidatureCandidatResource +
 * StatutPublicResolver, D-3b-7) : la réponse de GET /api/candidature est
 * strictement inchangée avant / après une vérification qui bascule le dossier
 * en non éligible côté évaluateur.
 */
class NonFuiteVersCandidatTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private User $candidat;

    private User $evaluateur;

    private Candidature $candidature;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->seedBareme();
        $this->candidat = $this->creerCandidat('cand@casa-demo.ci');
        $this->evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');

        // Parcours réel : le candidat remplit + soumet, puis l'admin affecte.
        $id = $this->actingAs($this->candidat)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');
        $this->candidature = Candidature::findOrFail($id);
        $this->rendreCandidatureComplete($this->candidature);
        $this->actingAs($this->candidat)->postJson("/api/candidatures/{$id}/soumettre")->assertOk();

        // Affectation (simulée — action admin, lot ultérieur).
        $this->candidature->forceFill([
            'evaluateur_id' => $this->evaluateur->membreEquipe->id,
            'statut_interne' => 'en_instruction',
        ])->saveQuietly();
    }

    public function test_GET_candidature_du_candidat_inchange_apres_verification_non_eligible(): void
    {
        $avant = $this->actingAs($this->candidat)->getJson('/api/candidature');
        $avant->assertOk()->assertJsonPath('data.statut_public', 'en_cours_de_traitement');

        // L'évaluateur confirme un diplôme CEPE -> dossier non éligible.
        $this->actingAs($this->evaluateur)
            ->putJson("/api/evaluateur/candidatures/{$this->candidature->id}/verification", ['diplome_verifie' => 'cepe'])
            ->assertOk()
            ->assertJsonPath('data.statut_eligibilite_interne', 'non_eligible');

        $this->assertSame('non_eligible', $this->candidature->fresh()->statut_eligibilite_interne);

        // La réponse candidat est IDENTIQUE (octet pour octet).
        $apres = $this->actingAs($this->candidat)->getJson('/api/candidature');
        $apres->assertOk();
        $this->assertSame($avant->getContent(), $apres->getContent());
    }

    public function test_GET_candidature_du_candidat_inchange_apres_notation_et_verrouillage(): void
    {
        $this->candidature->forceFill([
            'evaluateur_id' => $this->evaluateur->membreEquipe->id,
            'statut_interne' => 'en_instruction',
        ])->saveQuietly();
        $cid = $this->candidature->id;

        $avant = $this->actingAs($this->candidat)->getJson('/api/candidature');
        $avant->assertOk()->assertJsonPath('data.statut_public', 'en_cours_de_traitement');

        // Parcours évaluateur COMPLET : vérification → note MO.04 + commentaire → validation.
        $this->actingAs($this->evaluateur)
            ->putJson("/api/evaluateur/candidatures/{$cid}/verification", ['nationalite_confirmee' => true, 'diplome_verifie' => 'bac'])
            ->assertOk();
        $this->actingAs($this->evaluateur)
            ->putJson("/api/evaluateur/candidatures/{$cid}/evaluation", [
                'mo04_note_etoiles' => 4,
                'commentaire_evaluateur' => 'Score interne élevé — ne doit jamais fuir vers le candidat.',
            ])->assertOk();
        $this->actingAs($this->evaluateur)
            ->postJson("/api/evaluateur/candidatures/{$cid}/evaluation/validation")
            ->assertOk()
            ->assertJsonPath('data.verrouille', true);

        $this->assertSame('evalue', $this->candidature->fresh()->statut_interne);
        $this->assertDatabaseHas('evaluation_dossier', ['candidature_id' => $cid, 'valide' => true]);

        // La réponse candidat est IDENTIQUE, octet pour octet (vrai chemin, D-3b-7).
        $apres = $this->actingAs($this->candidat)->getJson('/api/candidature');
        $apres->assertOk();
        $this->assertSame($avant->getContent(), $apres->getContent());
    }

    public function test_aucune_reponse_candidat_ne_contient_de_donnee_de_score(): void
    {
        $this->candidature->forceFill([
            'evaluateur_id' => $this->evaluateur->membreEquipe->id,
            'statut_interne' => 'en_instruction',
        ])->saveQuietly();
        $cid = $this->candidature->id;

        $this->actingAs($this->evaluateur)
            ->putJson("/api/evaluateur/candidatures/{$cid}/verification", ['nationalite_confirmee' => true, 'diplome_verifie' => 'bac'])
            ->assertOk();
        $this->actingAs($this->evaluateur)
            ->putJson("/api/evaluateur/candidatures/{$cid}/evaluation", ['mo04_note_etoiles' => 5])
            ->assertOk();
        $this->actingAs($this->evaluateur)
            ->postJson("/api/evaluateur/candidatures/{$cid}/evaluation/validation")
            ->assertOk();

        foreach ([
            $this->actingAs($this->candidat)->getJson('/api/candidature'),
            $this->actingAs($this->candidat)->getJson("/api/candidatures/{$cid}"),
        ] as $reponse) {
            $body = $reponse->getContent();
            foreach ([
                'evaluation', 'evaluation_dossier', 'score', 'score_total', 'score_obtenu',
                'score_rubrique', 'commentaire_evaluateur', 'dossier_verrouille', 'verrouille',
                'mo04_note_etoiles', 'snapshot', 'grille', '/65', 'evalue',
            ] as $interdit) {
                $this->assertStringNotContainsString($interdit, $body, "« {$interdit} » ne doit pas fuir vers le candidat");
            }
        }
    }

    public function test_GET_candidature_du_candidat_inchange_apres_validation_d_entretien(): void
    {
        $cid = $this->candidature->id;

        // Dossier /65 verrouillé (état post-4b) — préalable à l'entretien.
        $this->actingAs($this->evaluateur)
            ->putJson("/api/evaluateur/candidatures/{$cid}/verification", ['nationalite_confirmee' => true, 'diplome_verifie' => 'bac'])->assertOk();
        $this->actingAs($this->evaluateur)
            ->putJson("/api/evaluateur/candidatures/{$cid}/evaluation", ['mo04_note_etoiles' => 4])->assertOk();
        $this->actingAs($this->evaluateur)
            ->postJson("/api/evaluateur/candidatures/{$cid}/evaluation/validation")->assertOk();

        $avant = $this->actingAs($this->candidat)->getJson('/api/candidature');
        $avant->assertOk()->assertJsonPath('data.statut_public', 'en_cours_de_traitement');

        // Parcours entretien COMPLET : planif → présence + sous-notes → validation.
        $this->actingAs($this->evaluateur)->putJson("/api/evaluateur/candidatures/{$cid}/entretien", [
            'date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Le Plateau',
            'presence' => 'present', 'observation' => 'Interne — jamais visible du candidat.',
            'notes' => ['PRES.01' => 3, 'REL.03' => 4, 'MOE.01' => 3],
        ])->assertOk();
        $this->actingAs($this->evaluateur)
            ->postJson("/api/evaluateur/candidatures/{$cid}/entretien/validation")
            ->assertOk()
            ->assertJsonPath('data.entretien.verrouille', true);

        $this->assertDatabaseHas('entretien', ['candidature_id' => $cid, 'statut' => 'valide']);
        $this->assertSame('evalue', $this->candidature->fresh()->statut_interne);

        // La réponse candidat est IDENTIQUE, octet pour octet (vrai chemin, D-3b-7).
        $apres = $this->actingAs($this->candidat)->getJson('/api/candidature');
        $apres->assertOk();
        $this->assertSame($avant->getContent(), $apres->getContent());
    }

    public function test_aucune_reponse_candidat_ne_contient_de_donnee_d_entretien(): void
    {
        $cid = $this->candidature->id;

        $this->actingAs($this->evaluateur)
            ->putJson("/api/evaluateur/candidatures/{$cid}/verification", ['nationalite_confirmee' => true, 'diplome_verifie' => 'bac'])->assertOk();
        $this->actingAs($this->evaluateur)
            ->putJson("/api/evaluateur/candidatures/{$cid}/evaluation", ['mo04_note_etoiles' => 5])->assertOk();
        $this->actingAs($this->evaluateur)
            ->postJson("/api/evaluateur/candidatures/{$cid}/evaluation/validation")->assertOk();
        $this->actingAs($this->evaluateur)->putJson("/api/evaluateur/candidatures/{$cid}/entretien", [
            'date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Le Plateau', 'presence' => 'present',
            'notes' => ['PRES.01' => 3, 'REL.03' => 4],
        ])->assertOk();
        $this->actingAs($this->evaluateur)->postJson("/api/evaluateur/candidatures/{$cid}/entretien/validation")->assertOk();

        foreach ([
            $this->actingAs($this->candidat)->getJson('/api/candidature'),
            $this->actingAs($this->candidat)->getJson("/api/candidatures/{$cid}"),
        ] as $reponse) {
            $body = $reponse->getContent();
            foreach ([
                // « entretien » nu apparaît légitimement dans un nom de filière
                // (« entretien-hotelier ») : on cible la clé JSON et les champs.
                '"entretien"', 'entretien_id', 'entretien_verrouille',
                'sous_critere', 'sous_note', 'note_sous', 'points_attribues',
                'presence', 'observation', 'score_total', 'score_entretien', '/35',
                'PRES.0', 'REL.0', 'MOE.0', 'planifie', 'realise', 'snapshot',
            ] as $interdit) {
                $this->assertStringNotContainsString($interdit, $body, "« {$interdit} » ne doit pas fuir vers le candidat");
            }
        }
    }

    public function test_aucune_reponse_candidat_ne_contient_de_donnee_de_verification(): void
    {
        $this->actingAs($this->evaluateur)
            ->putJson("/api/evaluateur/candidatures/{$this->candidature->id}/verification", [
                'nationalite_confirmee' => false, 'diplome_verifie' => 'cepe',
            ])->assertOk();

        foreach ([
            $this->actingAs($this->candidat)->getJson('/api/candidature'),
            $this->actingAs($this->candidat)->getJson("/api/candidatures/{$this->candidature->id}"),
            $this->actingAs($this->candidat)->getJson("/api/candidatures/{$this->candidature->id}/pieces"),
        ] as $reponse) {
            $body = $reponse->getContent();
            foreach ([
                'verification', 'nationalite_confirmee', 'diplome_verifie', 'verifie_par',
                'statut_interne', 'statut_eligibilite_interne', 'non_eligible',
                'critere', 'eliminatoire', 'SC.04', 'en_instruction',
            ] as $interdit) {
                $this->assertStringNotContainsString($interdit, $body, "« {$interdit} » ne doit pas fuir vers le candidat");
            }
        }
    }

    public function test_le_candidat_ne_peut_pas_appeler_les_routes_evaluateur(): void
    {
        $this->actingAs($this->candidat)
            ->getJson("/api/evaluateur/candidatures/{$this->candidature->id}")
            ->assertStatus(403); // role:evaluateur,administrateur
        $this->actingAs($this->candidat)
            ->putJson("/api/evaluateur/candidatures/{$this->candidature->id}/verification", ['diplome_verifie' => 'bac'])
            ->assertStatus(403);
    }
}
