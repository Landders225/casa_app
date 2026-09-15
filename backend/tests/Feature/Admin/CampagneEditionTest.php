<?php

namespace Tests\Feature\Admin;

use App\Models\Campagne;
use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;
use Tests\TestCase;

/**
 * Édition des informations générales d'une campagne — nom/dates (Lot 17,
 * D-6a-2, Étape 1 Q3).
 *
 * Découpage cosmétique (nom) vs effet réel sur le processus (dates) : une
 * campagne `cloturee` a les dates VERROUILLÉES, le nom reste ÉDITABLE.
 * `statut` n'est jamais ici — les transitions restent `PATCH .../{id}`
 * (Lot 6a, inchangé, testé dans `GestionFiliereCampagneTest`).
 */
class CampagneEditionTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private User $admin;

    private Campagne $campagne;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferentiels();
        $this->admin = $this->creerAdmin('admin@casa-demo.ci');
        $this->campagne = Campagne::ouverte()->firstOrFail();
    }

    private function url(): string
    {
        return "/api/admin/campagnes/{$this->campagne->id}";
    }

    public function test_edite_le_nom_et_les_dates_sur_une_campagne_ouverte(): void
    {
        $reponse = $this->actingAs($this->admin)->putJson($this->url(), [
            'nom' => 'Cohorte 1 — 2026 (renommée)',
            'date_ouverture' => '2026-05-01',
            'date_cloture' => '2026-07-31', // repoussée
        ])->assertOk();

        $this->assertSame('Cohorte 1 — 2026 (renommée)', $reponse->json('data.nom'));
        $this->assertSame('2026-07-31', $reponse->json('data.date_cloture'));
        $this->assertSame('ouverte', $reponse->json('data.statut'), 'le statut ne bouge jamais ici');
    }

    public function test_campagne_cloturee_dates_verrouillees_409(): void
    {
        $this->campagne->update(['statut' => 'cloturee']);

        $this->actingAs($this->admin)->putJson($this->url(), [
            'nom' => $this->campagne->nom,
            'date_ouverture' => $this->campagne->date_ouverture->toDateString(),
            'date_cloture' => '2026-12-31', // tentative de repousser une clôture déjà passée
        ])->assertStatus(409);

        $this->assertSame('2026-06-30', $this->campagne->fresh()->date_cloture->toDateString());
    }

    public function test_campagne_cloturee_nom_reste_editable(): void
    {
        $this->campagne->update(['statut' => 'cloturee']);

        $reponse = $this->actingAs($this->admin)->putJson($this->url(), [
            'nom' => 'Cohorte 1 — 2026 (archivée)',
            'date_ouverture' => $this->campagne->date_ouverture->toDateString(),
            'date_cloture' => $this->campagne->date_cloture->toDateString(), // dates INCHANGÉES
        ])->assertOk();

        $this->assertSame('Cohorte 1 — 2026 (archivée)', $reponse->json('data.nom'));
        $this->assertSame('cloturee', $reponse->json('data.statut'));
    }

    public function test_date_cloture_avant_ouverture_refusee_422(): void
    {
        $this->actingAs($this->admin)->putJson($this->url(), [
            'nom' => $this->campagne->nom,
            'date_ouverture' => '2026-08-01',
            'date_cloture' => '2026-07-01',
        ])->assertStatus(422)->assertJsonValidationErrors('date_cloture');
    }

    public function test_audit_ecrit_seulement_si_quelque_chose_change(): void
    {
        // Rejouer les MÊMES valeurs : idempotent, aucune ligne d'audit.
        $this->actingAs($this->admin)->putJson($this->url(), [
            'nom' => $this->campagne->nom,
            'date_ouverture' => $this->campagne->date_ouverture->toDateString(),
            'date_cloture' => $this->campagne->date_cloture->toDateString(),
        ])->assertOk();
        $this->assertSame(0, JournalAudit::where('module', 'Campagnes')->where('action', 'Modification de campagne')->count());

        // Un vrai changement : une ligne d'audit.
        $this->actingAs($this->admin)->putJson($this->url(), [
            'nom' => 'Renommée',
            'date_ouverture' => $this->campagne->date_ouverture->toDateString(),
            'date_cloture' => $this->campagne->date_cloture->toDateString(),
        ])->assertOk();

        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $this->admin->id,
            'action' => 'Modification de campagne',
            'module' => 'Campagnes',
        ]);
        $this->assertSame(1, JournalAudit::where('module', 'Campagnes')->where('action', 'Modification de campagne')->count());
    }

    public function test_edition_admin_only_403(): void
    {
        $body = [
            'nom' => $this->campagne->nom,
            'date_ouverture' => $this->campagne->date_ouverture->toDateString(),
            'date_cloture' => $this->campagne->date_cloture->toDateString(),
        ];

        foreach ([$this->creerEvaluateur('e@casa-demo.ci'), $this->creerCandidat('c@casa-demo.ci')] as $intrus) {
            $this->actingAs($intrus)->putJson($this->url(), $body)->assertStatus(403);
        }
    }
}
