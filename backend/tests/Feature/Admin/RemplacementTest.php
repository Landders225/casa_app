<?php

namespace Tests\Feature\Admin;

use App\Models\Campagne;
use App\Models\Candidature;
use App\Models\MembreEquipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Remplacement d'un candidat retenu indisponible (Lot 6b, ADR-15) — acte
 * POST-publication. Promotion du 1er de la liste d'attente (même filière, par
 * rang). Non-fuite : ni score, ni rang, ni le fait du remplacement.
 */
class RemplacementTest extends TestCase
{
    use CreeContexteClassement;
    use RefreshDatabase;

    private Campagne $campagne;
    private User $admin;
    private User $evaluateur;
    private Candidature $retenu;
    private Candidature $premiereAttente;
    private Candidature $secondeAttente;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedContexteClassement();
        $this->campagne = Campagne::ouverte()->firstOrFail();
        $this->admin = $this->creerAdmin('admin@casa-demo.ci');
        $this->evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');

        // Quota cuisine ramené à 1 : 1 retenu, le reste en liste d'attente.
        DB::table('campagne_filiere')
            ->where('campagne_id', $this->campagne->id)
            ->where('filiere_id', $this->idFiliere('cuisine'))
            ->update(['quota' => 1]);

        // Scores strictement décroissants -> rangs 1, 2, 3.
        $this->retenu = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->premiereAttente = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 50.0, 25.0);
        $this->secondeAttente = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 40.0, 20.0);
    }

    private function publier(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/classement")->assertOk();
        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/publier")->assertOk();
    }

    private function candidatUser(Candidature $c): User
    {
        return User::findOrFail($c->fresh()->candidat->utilisateur_id);
    }

    private function decision(Candidature $c): string
    {
        return DB::table('decision_candidature')->where('candidature_id', $c->id)->value('decision');
    }

    public function test_remplacement_promeut_le_premier_de_la_liste_d_attente(): void
    {
        $this->publier();
        $this->assertSame('retenu', $this->decision($this->retenu));
        $this->assertSame('liste_attente', $this->decision($this->premiereAttente));

        $this->actingAs($this->admin)->postJson('/api/admin/remplacements', [
            'candidature_id' => $this->retenu->id,
            'motif' => 'Le lauréat a trouvé un emploi et se désiste.',
        ])->assertOk()
            ->assertJsonPath('data.indisponible', $this->retenu->numero_dossier)
            ->assertJsonPath('data.promu', $this->premiereAttente->numero_dossier);

        $this->assertSame('indisponible', $this->decision($this->retenu));
        $this->assertSame('retenu', $this->decision($this->premiereAttente));
        $this->assertSame('liste_attente', $this->decision($this->secondeAttente));

        // Les rangs ne bougent pas (🔴).
        $this->assertSame(1, (int) DB::table('decision_candidature')
            ->where('candidature_id', $this->retenu->id)->value('rang'));
        $this->assertSame(2, (int) DB::table('decision_candidature')
            ->where('candidature_id', $this->premiereAttente->id)->value('rang'));

        $this->assertDatabaseHas('remplacement', [
            'candidature_indisponible_id' => $this->retenu->id,
            'candidature_promue_id' => $this->premiereAttente->id,
            'effectue_par' => $this->admin->membreEquipe->id,
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'action' => 'Remplacement de candidat indisponible',
            'module' => 'Classement',
            'ancienne_valeur' => 'Retenu',
        ]);
    }

    public function test_le_promu_voit_retenu_et_le_sortant_indisponible_sans_rien_d_autre(): void
    {
        $this->publier();

        $this->actingAs($this->admin)->postJson('/api/admin/remplacements', [
            'candidature_id' => $this->retenu->id,
            'motif' => 'Désistement.',
        ])->assertOk();

        $reponseSortant = $this->actingAs($this->candidatUser($this->retenu))->getJson('/api/candidature');
        $reponseSortant->assertOk()
            ->assertJsonPath('data.statut_public', 'decision_publiee')
            ->assertJsonPath('data.decision', 'indisponible');

        $reponsePromu = $this->actingAs($this->candidatUser($this->premiereAttente))->getJson('/api/candidature');
        $reponsePromu->assertOk()
            ->assertJsonPath('data.statut_public', 'decision_publiee')
            ->assertJsonPath('data.decision', 'retenu');

        // `rang` apparaît légitimement (classement de préférences du candidat) ;
        // on cible ce qui relèverait du classement/remplacement interne.
        foreach ([$reponseSortant->getContent(), $reponsePromu->getContent()] as $body) {
            foreach ([
                'remplacement', 'promu', 'motif_interne', 'score_final',
                'score_dossier', 'score_entretien', 'departage', 'decision_candidature',
            ] as $interdit) {
                $this->assertStringNotContainsString($interdit, $body, "« {$interdit} » ne doit pas fuir vers le candidat");
            }
        }
        // Le sortant n'apprend pas qu'il a été « remplacé » : aucune mention de la promotion.
        $reponseSortant->assertJsonMissingPath('data.score')->assertJsonMissingPath('data.rang_classement');
    }

    public function test_remplacement_sans_motif_422(): void
    {
        $this->publier();

        $this->actingAs($this->admin)->postJson('/api/admin/remplacements', [
            'candidature_id' => $this->retenu->id,
        ])->assertStatus(422)->assertJsonValidationErrors('motif');

        $this->assertSame('retenu', $this->decision($this->retenu));
    }

    public function test_remplacement_avant_publication_422(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/classement")->assertOk();

        $this->actingAs($this->admin)->postJson('/api/admin/remplacements', [
            'candidature_id' => $this->retenu->id,
            'motif' => 'Trop tôt.',
        ])->assertStatus(422);

        $this->assertDatabaseCount('remplacement', 0);
    }

    public function test_remplacement_sur_un_candidat_non_retenu_422(): void
    {
        $this->publier();

        $this->actingAs($this->admin)->postJson('/api/admin/remplacements', [
            'candidature_id' => $this->premiereAttente->id, // liste_attente, pas retenu
            'motif' => 'Cible invalide.',
        ])->assertStatus(422);

        $this->assertDatabaseCount('remplacement', 0);
    }

    public function test_liste_d_attente_vide_remplacement_trace_sans_promu(): void
    {
        // Quota 3 : les 3 candidats sont retenus, personne en attente.
        DB::table('campagne_filiere')
            ->where('campagne_id', $this->campagne->id)
            ->where('filiere_id', $this->idFiliere('cuisine'))
            ->update(['quota' => 3]);

        $this->publier();
        $this->assertSame('retenu', $this->decision($this->secondeAttente));

        $this->actingAs($this->admin)->postJson('/api/admin/remplacements', [
            'candidature_id' => $this->retenu->id,
            'motif' => "Aucun remplaçant disponible en liste d'attente.",
        ])->assertOk()
            ->assertJsonPath('data.indisponible', $this->retenu->numero_dossier)
            ->assertJsonPath('data.promu', null);

        $this->assertSame('indisponible', $this->decision($this->retenu));
        $this->assertDatabaseHas('remplacement', [
            'candidature_indisponible_id' => $this->retenu->id,
            'candidature_promue_id' => null,
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'action' => 'Remplacement de candidat indisponible',
            'nouvelle_valeur' => 'Indisponible · aucun candidat en liste d’attente à promouvoir',
        ]);
    }

    public function test_remplacement_sans_membre_equipe_422(): void
    {
        $this->publier(); // publiée par $this->admin (profil équipe intact)

        // Un second admin, sans profil équipe : impossible de tracer l'auteur.
        $sansProfil = $this->creerAdmin('admin2@casa-demo.ci');
        MembreEquipe::where('utilisateur_id', $sansProfil->id)->delete();

        $this->actingAs($sansProfil->fresh())->postJson('/api/admin/remplacements', [
            'candidature_id' => $this->retenu->id,
            'motif' => 'Profil équipe manquant.',
        ])->assertStatus(422);

        $this->assertDatabaseCount('remplacement', 0);
    }

    public function test_remplacement_reserve_a_l_administrateur(): void
    {
        $this->publier();
        $candidat = $this->candidatUser($this->retenu);

        foreach ([$this->evaluateur, $candidat] as $intrus) {
            $this->actingAs($intrus)->postJson('/api/admin/remplacements', [
                'candidature_id' => $this->retenu->id,
                'motif' => 'Tentative.',
            ])->assertForbidden();
        }

        $this->assertDatabaseCount('remplacement', 0);
    }
}
