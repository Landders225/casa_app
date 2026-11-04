<?php

namespace Tests\Feature\Console;

use App\Models\Campagne;
use App\Models\JournalAudit;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;
use Tests\TestCase;

/**
 * `casa:rouvrir-campagne` (ADR-43) — réouverture exceptionnelle, HORS
 * interface, de l'exception à l'irréversibilité décrite à l'ADR-23/ADR-34.
 * Un cas d'échec par garde-fou, dans le MÊME ORDRE que la commande ; aucune
 * écriture (statut, date, journal_audit) ne doit survenir sur un échec.
 */
class RouvrirCampagneCommandTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private const QUESTION = 'Confirmer la réouverture exceptionnelle ?';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 10:00:00');
        $this->seedReferentiels();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function campagneCloturee(): Campagne
    {
        $campagne = Campagne::ouverte()->firstOrFail();
        $campagne->update(['statut' => 'cloturee']);

        return $campagne->fresh();
    }

    public function test_succes_nominal(): void
    {
        $campagne = $this->campagneCloturee();
        $admin = $this->creerAdmin();
        $ancienneCloture = $campagne->date_cloture->toDateString();

        $this->artisan('casa:rouvrir-campagne', [
            'campagne' => $campagne->id,
            '--nouvelle-cloture' => '2026-12-31',
            '--raison' => 'Délai accordé par la direction',
            '--operateur' => $admin->email,
        ])
            ->expectsConfirmation(self::QUESTION, 'yes')
            ->assertSuccessful();

        $campagne->refresh();
        $this->assertSame('ouverte', $campagne->statut);
        $this->assertSame('2026-12-31', $campagne->date_cloture->toDateString());

        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $admin->id,
            'action' => 'Réouverture exceptionnelle de campagne',
            'module' => 'Campagnes',
            'objet' => $campagne->nom,
            'motif' => 'Délai accordé par la direction',
            'nouvelle_valeur' => 'Ouverte (clôture : 2026-12-31)',
            'ancienne_valeur' => "Clôturée (clôture : {$ancienneCloture})",
        ]);
    }

    public function test_echec_operateur_absent(): void
    {
        $campagne = $this->campagneCloturee();

        $this->artisan('casa:rouvrir-campagne', [
            'campagne' => $campagne->id,
            '--nouvelle-cloture' => '2026-12-31',
            '--raison' => 'x',
        ])->assertFailed();

        $this->assertSame('cloturee', $campagne->fresh()->statut);
        $this->assertSame(0, JournalAudit::count());
    }

    public function test_echec_operateur_inconnu(): void
    {
        $campagne = $this->campagneCloturee();

        $this->artisan('casa:rouvrir-campagne', [
            'campagne' => $campagne->id,
            '--nouvelle-cloture' => '2026-12-31',
            '--raison' => 'x',
            '--operateur' => 'inconnu-'.uniqid().'@casa-demo.ci',
        ])->assertFailed();

        $this->assertSame('cloturee', $campagne->fresh()->statut);
        $this->assertSame(0, JournalAudit::count());
    }

    public function test_echec_operateur_non_administrateur(): void
    {
        $campagne = $this->campagneCloturee();
        $evaluateur = $this->creerEvaluateur();

        $this->artisan('casa:rouvrir-campagne', [
            'campagne' => $campagne->id,
            '--nouvelle-cloture' => '2026-12-31',
            '--raison' => 'x',
            '--operateur' => $evaluateur->email,
        ])->assertFailed();

        $this->assertSame('cloturee', $campagne->fresh()->statut);
        $this->assertSame(0, JournalAudit::count());
    }

    public function test_echec_raison_vide(): void
    {
        $campagne = $this->campagneCloturee();
        $admin = $this->creerAdmin();

        $this->artisan('casa:rouvrir-campagne', [
            'campagne' => $campagne->id,
            '--nouvelle-cloture' => '2026-12-31',
            '--raison' => '   ',
            '--operateur' => $admin->email,
        ])->assertFailed();

        $this->assertSame('cloturee', $campagne->fresh()->statut);
        $this->assertSame(0, JournalAudit::count());
    }

    public function test_echec_nouvelle_cloture_absente(): void
    {
        $campagne = $this->campagneCloturee();
        $admin = $this->creerAdmin();

        $this->artisan('casa:rouvrir-campagne', [
            'campagne' => $campagne->id,
            '--raison' => 'x',
            '--operateur' => $admin->email,
        ])->assertFailed();

        $this->assertSame('cloturee', $campagne->fresh()->statut);
    }

    public function test_echec_nouvelle_cloture_format_invalide(): void
    {
        $campagne = $this->campagneCloturee();
        $admin = $this->creerAdmin();

        $this->artisan('casa:rouvrir-campagne', [
            'campagne' => $campagne->id,
            '--nouvelle-cloture' => '31/12/2026',
            '--raison' => 'x',
            '--operateur' => $admin->email,
        ])->assertFailed();

        $this->assertSame('cloturee', $campagne->fresh()->statut);
    }

    public function test_echec_nouvelle_cloture_date_inexistante(): void
    {
        $campagne = $this->campagneCloturee();
        $admin = $this->creerAdmin();

        $this->artisan('casa:rouvrir-campagne', [
            'campagne' => $campagne->id,
            '--nouvelle-cloture' => '2026-02-30', // n'existe pas (Carbon la « corrigerait » en 2026-03-02)
            '--raison' => 'x',
            '--operateur' => $admin->email,
        ])->assertFailed();

        $this->assertSame('cloturee', $campagne->fresh()->statut);
    }

    public function test_echec_nouvelle_cloture_pas_future(): void
    {
        $campagne = $this->campagneCloturee();
        $admin = $this->creerAdmin();

        $this->artisan('casa:rouvrir-campagne', [
            'campagne' => $campagne->id,
            '--nouvelle-cloture' => '2026-11-04', // = aujourd'hui (Carbon::setTestNow)
            '--raison' => 'x',
            '--operateur' => $admin->email,
        ])->assertFailed();

        $this->assertSame('cloturee', $campagne->fresh()->statut);
    }

    public function test_echec_campagne_introuvable(): void
    {
        $admin = $this->creerAdmin();

        $this->artisan('casa:rouvrir-campagne', [
            'campagne' => '00000000-0000-0000-0000-000000000000',
            '--nouvelle-cloture' => '2026-12-31',
            '--raison' => 'x',
            '--operateur' => $admin->email,
        ])->assertFailed();

        $this->assertSame(0, JournalAudit::count());
    }

    public function test_echec_uuid_invalide(): void
    {
        $admin = $this->creerAdmin();

        $this->artisan('casa:rouvrir-campagne', [
            'campagne' => 'pas-un-uuid',
            '--nouvelle-cloture' => '2026-12-31',
            '--raison' => 'x',
            '--operateur' => $admin->email,
        ])->assertFailed();
    }

    public function test_echec_campagne_pas_cloturee(): void
    {
        $campagne = Campagne::ouverte()->firstOrFail(); // déjà 'ouverte'
        $admin = $this->creerAdmin();

        $this->artisan('casa:rouvrir-campagne', [
            'campagne' => $campagne->id,
            '--nouvelle-cloture' => '2026-12-31',
            '--raison' => 'x',
            '--operateur' => $admin->email,
        ])->assertFailed();

        $this->assertSame('ouverte', $campagne->fresh()->statut);
        $this->assertSame(0, JournalAudit::count());
    }

    public function test_echec_autre_campagne_deja_ouverte(): void
    {
        $campagne = $this->campagneCloturee();
        $admin = $this->creerAdmin();

        // Une 2e campagne, déjà ouverte, cohabite.
        Campagne::create([
            'nom' => 'Cohorte 2 — 2026',
            'statut' => 'ouverte',
            'date_ouverture' => '2026-11-01',
            'date_cloture' => '2026-12-01',
            'places_totales' => 10,
        ]);

        $this->artisan('casa:rouvrir-campagne', [
            'campagne' => $campagne->id,
            '--nouvelle-cloture' => '2026-12-31',
            '--raison' => 'x',
            '--operateur' => $admin->email,
        ])->assertFailed();

        $this->assertSame('cloturee', $campagne->fresh()->statut);
        $this->assertSame(0, JournalAudit::count());
    }

    public function test_confirmation_refusee_naboutit_a_rien(): void
    {
        $campagne = $this->campagneCloturee();
        $admin = $this->creerAdmin();

        $this->artisan('casa:rouvrir-campagne', [
            'campagne' => $campagne->id,
            '--nouvelle-cloture' => '2026-12-31',
            '--raison' => 'x',
            '--operateur' => $admin->email,
        ])
            ->expectsConfirmation(self::QUESTION, 'no')
            ->assertSuccessful(); // annulation propre, pas une erreur

        $this->assertSame('cloturee', $campagne->fresh()->statut);
        $this->assertSame(0, JournalAudit::count());
    }

    public function test_non_regression_apres_reouverture(): void
    {
        $campagne = $this->campagneCloturee();
        $admin = $this->creerAdmin();

        $this->artisan('casa:rouvrir-campagne', [
            'campagne' => $campagne->id,
            '--nouvelle-cloture' => '2026-12-31',
            '--raison' => 'x',
            '--operateur' => $admin->email,
        ])
            ->expectsConfirmation(self::QUESTION, 'yes')
            ->assertSuccessful();

        // POST /api/candidatures accepte à nouveau.
        $candidat = $this->creerCandidat();
        $this->actingAs($candidat)->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->assertCreated();

        // PUT .../{id} (dates) redevient modifiable (statut n'est plus 'cloturee').
        $this->actingAs($admin)->putJson("/api/admin/campagnes/{$campagne->id}", [
            'nom' => $campagne->nom,
            'date_ouverture' => $campagne->date_ouverture->toDateString(),
            'date_cloture' => '2027-01-15',
        ])->assertOk();
    }
}
