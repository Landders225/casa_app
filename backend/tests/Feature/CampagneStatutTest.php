<?php

namespace Tests\Feature;

use App\Models\Campagne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;
use Tests\TestCase;

/**
 * `GET /api/campagne-ouverte` (ADR-42) — signal minimal pour l'espace
 * candidat, AUCUNE restriction de rôle (tout compte authentifié). Liste
 * blanche d'UN SEUL booléen — jamais l'id/nom/dates de la campagne.
 */
class CampagneStatutTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferentiels(); // campagne "Cohorte 1 — 2026", statut 'ouverte'
    }

    public function test_refuse_sans_authentification(): void
    {
        $this->getJson('/api/campagne-ouverte')->assertStatus(401);
    }

    public function test_vrai_pour_les_trois_roles_quand_une_campagne_est_ouverte(): void
    {
        foreach ([$this->creerCandidat(), $this->creerEvaluateur(), $this->creerAdmin()] as $acteur) {
            $reponse = $this->actingAs($acteur)->getJson('/api/campagne-ouverte')->assertOk();
            $this->assertTrue($reponse->json('data.campagne_ouverte'));
        }
    }

    public function test_faux_quand_aucune_campagne_nest_ouverte(): void
    {
        Campagne::query()->update(['statut' => 'cloturee']);

        $reponse = $this->actingAs($this->creerCandidat())->getJson('/api/campagne-ouverte')->assertOk();
        $this->assertFalse($reponse->json('data.campagne_ouverte'));
    }

    public function test_faux_quand_la_seule_campagne_est_en_brouillon(): void
    {
        Campagne::query()->update(['statut' => 'brouillon']);

        $reponse = $this->actingAs($this->creerCandidat())->getJson('/api/campagne-ouverte')->assertOk();
        $this->assertFalse($reponse->json('data.campagne_ouverte'));
    }

    public function test_liste_blanche_stricte_un_seul_booleen(): void
    {
        $reponse = $this->actingAs($this->creerCandidat())->getJson('/api/campagne-ouverte')->assertOk();

        $this->assertSame(['campagne_ouverte'], array_keys($reponse->json('data')));

        // Aucune fuite d'id/nom/dates de la campagne dans la réponse brute.
        $campagne = Campagne::query()->firstOrFail();
        $body = $reponse->getContent();
        foreach ([$campagne->id, $campagne->nom, $campagne->date_cloture->toDateString()] as $interdit) {
            $this->assertStringNotContainsString((string) $interdit, $body);
        }
    }
}
