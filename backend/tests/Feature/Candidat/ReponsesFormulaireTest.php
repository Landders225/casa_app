<?php

namespace Tests\Feature\Candidat;

use App\Models\ReponseFormulaire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReponsesFormulaireTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    private User $user;

    private string $candidatureId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferentiels();
        $this->user = $this->creerCandidat();
        $this->candidatureId = $this->actingAs($this->user)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');
    }

    private function patchReponses(array $data)
    {
        return $this->actingAs($this->user)
            ->patchJson("/api/candidatures/{$this->candidatureId}/reponses", $data);
    }

    public function test_maj_partielle_persiste_et_se_relit(): void
    {
        $this->patchReponses([
            'sc01_scolarise_actuellement' => 'non',
            'sc02_derniere_classe' => 'terminale',
            'sc03_document_justifiant_niveau' => 'oui',
            'sc05_beneficiaire_formation_actuelle' => 'non',
        ])->assertOk()->assertJsonPath('data.reponses.sc02_derniere_classe', 'terminale');

        // Deuxième PATCH : autre étape, ne touche pas la première.
        $this->patchReponses([
            'langue_ecrit' => 3, 'langue_parle' => 2, 'langue_comprehension' => 3,
            'mo04_lettre_motivation' => 'Je souhaite vraiment intégrer cette formation certifiante.',
        ])->assertOk();

        $reponses = $this->actingAs($this->user)
            ->getJson("/api/candidatures/{$this->candidatureId}")
            ->json('data.reponses');

        $this->assertSame('terminale', $reponses['sc02_derniere_classe']);
        $this->assertSame(3, $reponses['langue_ecrit']);
        $this->assertStringContainsString('certifiante', $reponses['mo04_lettre_motivation']);
    }

    public function test_valeur_hors_domaine_rejetee_422(): void
    {
        $this->patchReponses(['sc02_derniere_classe' => 'licence'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sc02_derniere_classe');

        $this->patchReponses(['langue_ecrit' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('langue_ecrit');

        $this->patchReponses(['di02_contraintes' => 'enorme'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('di02_contraintes');
    }

    public function test_champ_note_evaluateur_interdit(): void
    {
        $this->patchReponses(['mo04_note_etoiles' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mo04_note_etoiles');

        $this->assertNull(ReponseFormulaire::find($this->candidatureId)->mo04_note_etoiles);
    }

    public function test_champs_nationalite_diplome_statut_interdits(): void
    {
        foreach (['nationalite' => 'ivoirienne', 'diplome' => 'bac', 'sc04' => 'bac', 'statut_interne' => 'evalue', 'cqp_confirme' => true] as $champ => $valeur) {
            $this->patchReponses([$champ => $valeur])
                ->assertStatus(422)
                ->assertJsonValidationErrors($champ);
        }
    }

    public function test_lettre_motivation_bornee_a_500(): void
    {
        $this->patchReponses(['mo04_lettre_motivation' => str_repeat('a', 501)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mo04_lettre_motivation');

        $this->patchReponses(['mo04_lettre_motivation' => str_repeat('a', 500)])->assertOk();
    }

    public function test_null_explicite_efface_un_champ(): void
    {
        $this->patchReponses(['sc01_scolarise_actuellement' => 'non'])->assertOk();
        $this->patchReponses(['sc01_scolarise_actuellement' => null])->assertOk();

        $this->assertNull(ReponseFormulaire::find($this->candidatureId)->sc01_scolarise_actuellement);
    }
}
