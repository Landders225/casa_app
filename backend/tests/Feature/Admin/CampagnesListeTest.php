<?php

namespace Tests\Feature\Admin;

use App\Models\Campagne;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;
use Tests\TestCase;

/**
 * `GET /api/admin/campagnes` (Lot 8d-1) — comble le trou d'API découvert à
 * l'Étape 1 : aucun point de lecture n'existait pour lister les campagnes
 * (seule la transition `PATCH .../{campagne}` existait, nécessitant déjà un
 * id connu). Administrateur strict, liste blanche VERROUILLÉE aux champs de
 * gestion.
 */
class CampagnesListeTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferentiels(); // Cohorte 1 — 2026, ouverte
        $this->admin = $this->creerAdmin('admin@casa-demo.ci');
    }

    public function test_liste_les_campagnes(): void
    {
        Campagne::create([
            'nom' => 'Cohorte 2 — 2027', 'statut' => 'brouillon',
            'date_ouverture' => '2027-01-01', 'date_cloture' => '2027-02-28', 'places_totales' => 90,
        ]);

        $reponse = $this->actingAs($this->admin)->getJson('/api/admin/campagnes')->assertOk();

        $this->assertCount(2, $reponse->json('data'));
        $noms = collect($reponse->json('data'))->pluck('nom')->all();
        $this->assertContains('Cohorte 1 — 2026', $noms);
        $this->assertContains('Cohorte 2 — 2027', $noms);
    }

    /**
     * Lot 17 : la liste blanche s'ÉTEND (filieres/quota + 3 indicateurs, pour
     * alimenter l'écran Quotas — Étape 1 Q4/Q5), mais reste STRICTE : ni
     * description, ni timestamps, ni rien du classement lui-même (rang, motifs).
     */
    public function test_liste_blanche_stricte_champs_de_gestion_seulement(): void
    {
        $reponse = $this->actingAs($this->admin)->getJson('/api/admin/campagnes')->assertOk();

        foreach ($reponse->json('data') as $ligne) {
            $this->assertSame(
                ['id', 'nom', 'statut', 'date_ouverture', 'date_cloture', 'places_totales', 'classement_calcule', 'classement_perime', 'publiee', 'filieres'],
                array_keys($ligne),
            );
            foreach ($ligne['filieres'] as $f) {
                $this->assertSame(['id', 'code', 'nom', 'quota'], array_keys($f));
            }
        }

        // Ni la description, ni les timestamps, ni rien du classement lui-même.
        $body = $reponse->getContent();
        foreach (['description', 'created_at', 'updated_at', 'rang', 'motif_interne', 'motif_communicable'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $body);
        }
    }

    public function test_liste_expose_les_filieres_et_quotas_de_chaque_campagne(): void
    {
        $reponse = $this->actingAs($this->admin)->getJson('/api/admin/campagnes')->assertOk();

        $cohorte1 = collect($reponse->json('data'))->firstWhere('nom', 'Cohorte 1 — 2026');
        $this->assertCount(5, $cohorte1['filieres']); // 5 CQP seedées, quota 24 chacune
        $this->assertSame(24, $cohorte1['filieres'][0]['quota']);
        $this->assertFalse($cohorte1['classement_calcule']);
        $this->assertFalse($cohorte1['classement_perime']);
        $this->assertFalse($cohorte1['publiee']);
    }

    public function test_campagnes_admin_only_403(): void
    {
        foreach ([$this->creerEvaluateur('e@casa-demo.ci'), $this->creerCandidat('c@casa-demo.ci')] as $intrus) {
            $this->actingAs($intrus)->getJson('/api/admin/campagnes')->assertStatus(403);
        }
    }
}
