<?php

namespace Tests\Feature\Candidat;

use App\Models\Candidature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CandidatureBrouillonTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferentiels();
    }

    public function test_creation_de_candidature(): void
    {
        $user = $this->creerCandidat();
        $filiereId = $this->idFiliere('cuisine');

        $response = $this->actingAs($user)->postJson('/api/candidatures', ['filiere_id' => $filiereId]);

        $response->assertCreated()
            ->assertJsonPath('data.statut_public', 'brouillon')
            ->assertJsonPath('data.cqp_confirme', false)
            ->assertJsonPath('data.filiere.code', 'cuisine')
            ->assertJsonPath('data.campagne.nom', 'Cohorte 1 — 2026');

        $numero = $response->json('data.numero_dossier');
        $this->assertMatchesRegularExpression('/^CASA-2026-\d{6}$/', $numero);

        // 1-1 strict : jeu de réponses vide + classement pré-rempli (5 lignes).
        $candidature = Candidature::firstWhere('numero_dossier', $numero);
        $this->assertNotNull($candidature->reponseFormulaire);
        $this->assertCount(5, $candidature->classement);
        $this->assertSame(
            $filiereId,
            $candidature->classement()->where('rang', 1)->value('filiere_id'),
        );
    }

    public function test_une_seule_candidature_par_campagne(): void
    {
        $user = $this->creerCandidat();

        $this->actingAs($user)->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->assertCreated();

        $this->actingAs($user)->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('buanderie')])
            ->assertStatus(409);
    }

    public function test_filiere_hors_campagne_refusee(): void
    {
        $user = $this->creerCandidat();

        $this->actingAs($user)->postJson('/api/candidatures', ['filiere_id' => (string) \Illuminate\Support\Str::uuid()])
            ->assertStatus(422);
    }

    public function test_get_candidature_courante(): void
    {
        $user = $this->creerCandidat();
        $this->actingAs($user)->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')]);

        $this->actingAs($user)->getJson('/api/candidature')
            ->assertOk()
            ->assertJsonPath('data.filiere.code', 'cuisine')
            ->assertJsonStructure(['data' => ['id', 'numero_dossier', 'statut_public', 'reponses', 'experiences', 'classement']]);
    }

    public function test_get_candidature_courante_404_si_aucune(): void
    {
        $user = $this->creerCandidat();

        $this->actingAs($user)->getJson('/api/candidature')->assertStatus(404);
    }

    public function test_confirmer_filiere_est_irreversible_et_idempotent(): void
    {
        $user = $this->creerCandidat();
        $id = $this->actingAs($user)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');

        $this->actingAs($user)->postJson("/api/candidatures/{$id}/confirmer-filiere")
            ->assertOk()->assertJsonPath('data.cqp_confirme', true);

        // 2e appel : pas d'erreur.
        $this->actingAs($user)->postJson("/api/candidatures/{$id}/confirmer-filiere")
            ->assertOk()->assertJsonPath('data.cqp_confirme', true);

        $this->assertTrue(Candidature::find($id)->cqp_confirme);
    }

    public function test_resource_candidat_ne_fuit_aucun_champ_interne(): void
    {
        $user = $this->creerCandidat();
        $id = $this->actingAs($user)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');

        // Salir les colonnes internes en base pour prouver qu'elles ne sortent pas.
        Candidature::where('id', $id)->update([
            'statut_interne' => 'evalue',
            'statut_eligibilite_interne' => 'non_eligible',
            'dossier_verrouille' => true,
            'commentaire_evaluateur' => 'NE DOIT PAS FUITER',
        ]);
        \App\Models\ReponseFormulaire::where('candidature_id', $id)->update(['mo04_note_etoiles' => 4]);

        $body = $this->actingAs($user)->getJson("/api/candidatures/{$id}")->assertOk()->getContent();

        foreach ([
            'statut_interne', 'statut_eligibilite_interne', 'dossier_verrouille',
            'evaluateur_id', 'commentaire_evaluateur', 'date_evaluation',
            'mo04_note_etoiles', 'non_eligible', 'NE DOIT PAS FUITER',
        ] as $interdit) {
            $this->assertStringNotContainsString($interdit, $body, "« {$interdit} » ne doit pas apparaître dans la réponse candidat");
        }

        // statut_public reste neutre malgré statut_interne = evalue.
        $this->actingAs($user)->getJson("/api/candidatures/{$id}")
            ->assertJsonPath('data.statut_public', 'en_cours_de_traitement');
    }
}
