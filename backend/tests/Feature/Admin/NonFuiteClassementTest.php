<?php

namespace Tests\Feature\Admin;

use App\Models\Campagne;
use App\Models\Candidature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Règle reine du Lot 5a : le calcul du classement et l'écriture de
 * `decision_candidature` (SANS `publication`) ne changent RIEN pour le candidat.
 *
 * `StatutPublicResolver` est inchangé : toujours « en_cours_de_traitement ».
 * Test sur le VRAI chemin candidat (D-3b-7).
 */
class NonFuiteClassementTest extends TestCase
{
    use CreeContexteClassement;
    use RefreshDatabase;

    private Campagne $campagne;
    private User $admin;
    private User $evaluateur;
    private User $candidat;
    private Candidature $candidature;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedContexteClassement();
        $this->campagne = Campagne::ouverte()->firstOrFail();
        $this->admin = $this->creerAdmin('admin@casa-demo.ci');
        $this->evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');

        // Un candidat dont la candidature sera classée, + un concurrent pour un vrai classement.
        $this->candidature = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 30.0, 15.0);
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);

        $this->candidat = User::findOrFail($this->candidature->candidat->utilisateur_id);
    }

    public function test_GET_candidature_du_candidat_inchange_apres_calcul_du_classement(): void
    {
        $avant = $this->actingAs($this->candidat)->getJson('/api/candidature');
        $avant->assertOk()->assertJsonPath('data.statut_public', 'en_cours_de_traitement');

        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/classement")
            ->assertOk();

        // La décision est bien écrite en interne...
        $this->assertDatabaseHas('decision_candidature', ['candidature_id' => $this->candidature->id]);
        // ...mais aucune publication n'existe.
        $this->assertDatabaseCount('publication', 0);

        // La réponse candidat est IDENTIQUE, octet pour octet.
        $apres = $this->actingAs($this->candidat)->getJson('/api/candidature');
        $apres->assertOk();
        $this->assertSame($avant->getContent(), $apres->getContent());
    }

    public function test_aucune_reponse_candidat_ne_contient_de_donnee_de_classement(): void
    {
        // Calcul + motifs renseignés.
        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/classement")->assertOk();
        $this->actingAs($this->admin)
            ->putJson("/api/admin/candidatures/{$this->candidature->id}/decision/motifs", [
                'motif_interne' => 'Note interne à ne jamais divulguer.',
                'motif_communicable' => 'Message qui ne doit pas encore apparaître (pas de publication).',
            ])->assertOk();

        foreach ([
            $this->actingAs($this->candidat)->getJson('/api/candidature'),
            $this->actingAs($this->candidat)->getJson("/api/candidatures/{$this->candidature->id}"),
        ] as $reponse) {
            // Avant publication : `decision` / `motif_communicable` restent null
            // (clés en liste blanche, Lot 5b) ; rien du classement ne transparaît.
            $reponse->assertJsonPath('data.statut_public', 'en_cours_de_traitement')
                ->assertJsonPath('data.decision', null)
                ->assertJsonPath('data.motif_communicable', null);

            $body = $reponse->getContent();
            foreach ([
                'decision_candidature', 'score_final', 'liste_attente', 'non_retenu', '"retenu"',
                'motif_interne', 'departage', 'vulnerabilite',
                'Note interne', 'Message qui ne doit pas', 'decision_publiee',
            ] as $interdit) {
                $this->assertStringNotContainsString($interdit, $body, "« {$interdit} » ne doit pas fuir vers le candidat");
            }
        }
    }

    public function test_le_candidat_et_l_evaluateur_ne_peuvent_pas_lire_le_classement(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/classement")->assertOk();

        $this->actingAs($this->candidat)
            ->getJson("/api/admin/campagnes/{$this->campagne->id}/classement")->assertStatus(403);
        $this->actingAs($this->evaluateur)
            ->getJson("/api/admin/campagnes/{$this->campagne->id}/classement")->assertStatus(403);
    }
}
