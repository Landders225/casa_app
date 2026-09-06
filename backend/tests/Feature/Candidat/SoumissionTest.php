<?php

namespace Tests\Feature\Candidat;

use App\Models\Candidature;
use App\Models\CritereEliminatoireDeclenche;
use App\Models\Filiere;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SoumissionTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    private User $user;

    private Candidature $candidature;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->user = $this->creerCandidat();
        $id = $this->actingAs($this->user)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');
        $this->candidature = Candidature::findOrFail($id);
    }

    private function soumettre()
    {
        return $this->actingAs($this->user)->postJson("/api/candidatures/{$this->candidature->id}/soumettre");
    }

    public function test_soumission_d_un_dossier_complet_et_eligible(): void
    {
        $this->rendreCandidatureComplete($this->candidature);

        $this->soumettre()
            ->assertOk()
            ->assertExactJson(['data' => [
                'numero_dossier' => $this->candidature->numero_dossier,
                'statut_public' => 'en_cours_de_traitement',
            ]]);

        $fraiche = $this->candidature->fresh();
        $this->assertSame('soumis', $fraiche->statut_interne);
        $this->assertSame('eligible', $fraiche->statut_eligibilite_interne);
        $this->assertNotNull($fraiche->date_soumission);
        $this->assertSame(0, CritereEliminatoireDeclenche::where('candidature_id', $this->candidature->id)->count());

        // journal_audit (🔴) : une ligne "Soumission" avec le candidat comme auteur.
        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $this->user->id,
            'action' => 'Soumission de candidature',
            'module' => 'Candidatures',
        ]);
    }

    public function test_dossier_incomplet_renvoie_422_avec_le_detail(): void
    {
        // rien rempli
        $this->soumettre()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cqp_confirme', 'reponses.sc01_scolarise_actuellement', 'pieces_dossier'])
            ->assertJsonPath('message', 'Le dossier de candidature est incomplet.');

        // toujours en brouillon.
        $this->assertSame('brouillon', $this->candidature->fresh()->statut_interne);
    }

    public function test_re_soumission_bloquee_409(): void
    {
        $this->rendreCandidatureComplete($this->candidature);
        $this->soumettre()->assertOk();

        $this->soumettre()
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cette candidature a déjà été soumise.');
    }

    public function test_edition_3a_3b_fermee_apres_soumission_409(): void
    {
        $this->rendreCandidatureComplete($this->candidature);
        $this->soumettre()->assertOk();

        $id = $this->candidature->id;
        $this->actingAs($this->user)->patchJson("/api/candidatures/{$id}/reponses", ['sc01_scolarise_actuellement' => 'oui'])
            ->assertStatus(409);
        $this->actingAs($this->user)->postJson("/api/candidatures/{$id}/confirmer-filiere")
            ->assertStatus(409);
        $this->actingAs($this->user)->postJson("/api/candidatures/{$id}/experiences", ['domaine' => 'commerce', 'duree_categorie' => 'moins_6'])
            ->assertStatus(409);
        $this->actingAs($this->user)->putJson("/api/candidatures/{$id}/classement", ['ordre' => Filiere::pluck('id')->all()])
            ->assertStatus(409);
        $this->actingAs($this->user)->post("/api/candidatures/{$id}/pieces/cni", ['fichier' => $this->fichierPdf()])
            ->assertStatus(409);
    }

    public function test_lecture_reste_ouverte_et_statut_public_bascule(): void
    {
        $this->rendreCandidatureComplete($this->candidature);

        $this->actingAs($this->user)->getJson('/api/candidature')->assertJsonPath('data.statut_public', 'brouillon');

        $this->soumettre()->assertOk();

        $apres = $this->actingAs($this->user)->getJson('/api/candidature');
        $apres->assertOk()
            ->assertJsonPath('data.statut_public', 'en_cours_de_traitement')
            ->assertJsonPath('data.date_soumission', fn ($v) => $v !== null);

        // Aucun champ interne / d'éligibilité dans la vraie réponse candidat.
        $body = $apres->getContent();
        foreach (['statut_interne', 'statut_eligibilite', 'non_eligible', 'critere', 'eliminatoire'] as $mot) {
            $this->assertStringNotContainsStringIgnoringCase($mot, $body);
        }
    }

    public function test_soumission_de_la_candidature_d_un_autre_404(): void
    {
        $this->rendreCandidatureComplete($this->candidature);
        $autre = $this->creerCandidat('autre@casa-demo.ci');

        $this->actingAs($autre)->postJson("/api/candidatures/{$this->candidature->id}/soumettre")
            ->assertStatus(404);
    }
}
