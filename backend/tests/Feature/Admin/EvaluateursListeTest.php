<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;
use Tests\TestCase;

/**
 * `GET /api/admin/evaluateurs` (Lot 8d-1) — comble le trou d'API découvert à
 * l'Étape 1 : rien ne listait les évaluateurs pour peupler le sélecteur
 * d'affectation. Administrateur strict, liste blanche VERROUILLÉE (même
 * discipline que `FilieresPubliquesTest`).
 */
class EvaluateursListeTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->creerAdmin('admin@casa-demo.ci');
    }

    public function test_liste_les_evaluateurs_uniquement_pas_les_administrateurs_ni_les_candidats(): void
    {
        $eval1 = $this->creerEvaluateur('eval1@casa-demo.ci');
        $eval2 = $this->creerEvaluateur('eval2@casa-demo.ci');
        $this->creerAdmin('admin2@casa-demo.ci'); // membre_equipe mais PAS évaluateur -> absent
        $this->creerCandidat('cand@casa-demo.ci'); // pas un membre_equipe -> absent

        $reponse = $this->actingAs($this->admin)->getJson('/api/admin/evaluateurs')->assertOk();

        $ids = collect($reponse->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertCount(2, $ids);
        $this->assertSame(
            collect([$eval1->membreEquipe->id, $eval2->membreEquipe->id])->sort()->values()->all(),
            $ids,
        );
    }

    public function test_liste_blanche_stricte_aucun_email_aucune_donnee_de_compte(): void
    {
        $this->creerEvaluateur(email: 'secret-email@casa-demo.ci');

        $reponse = $this->actingAs($this->admin)->getJson('/api/admin/evaluateurs')->assertOk();

        foreach ($reponse->json('data') as $ligne) {
            $this->assertSame(['id', 'prenom', 'nom', 'poste'], array_keys($ligne));
        }

        // Aucune trace de l'e-mail, ni de l'id `utilisateur`, ni de timestamps.
        $body = $reponse->getContent();
        foreach (['secret-email@casa-demo.ci', 'utilisateur_id', 'email', 'created_at', 'updated_at'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $body);
        }
    }

    public function test_evaluateurs_admin_only_403(): void
    {
        foreach ([$this->creerEvaluateur('e@casa-demo.ci'), $this->creerCandidat('c@casa-demo.ci')] as $intrus) {
            $this->actingAs($intrus)->getJson('/api/admin/evaluateurs')->assertStatus(403);
        }
    }
}
