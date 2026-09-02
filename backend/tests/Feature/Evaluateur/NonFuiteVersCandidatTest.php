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
