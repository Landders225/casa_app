<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Notifications\InscriptionConfirmee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\TransportSmtpSimule;
use Tests\TestCase;

/**
 * Lot 18 (ADR-35) — `casa:renvoyer-echecs-smtp` : renvoi étalé (par lots
 * espacés) des notifications e-mail en échec, à la place d'un
 * `queue:retry all` brutal (cause de l'incident des 141 échecs).
 */
class RenvoyerEchecsSmtpCommandTest extends TestCase
{
    use RefreshDatabase;

    private function produireEchecsMail(int $nombre): void
    {
        TransportSmtpSimule::programmer(array_fill(0, $nombre, 550));
        Mail::extend('smtp-simule', fn () => new TransportSmtpSimule);
        config([
            'mail.mailers.smtp-simule' => ['transport' => 'smtp-simule'],
            'mail.default' => 'smtp-simule',
            'queue.default' => 'database',
        ]);

        User::factory()->count($nombre)->create()->each(
            fn (User $u) => $u->notify(new InscriptionConfirmee)
        );

        Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 3, '--backoff' => 10]);
    }

    public function test_dry_run_affiche_le_compte_sans_rien_renvoyer(): void
    {
        $this->produireEchecsMail(5);
        $this->assertDatabaseCount('failed_jobs', 5);

        Artisan::call('casa:renvoyer-echecs-smtp', ['--dry-run' => true]);

        $this->assertDatabaseCount('failed_jobs', 5);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertStringContainsString('5 échec', Artisan::output());
    }

    public function test_renvoie_par_lots_sans_doublon(): void
    {
        $this->produireEchecsMail(7);
        $this->assertDatabaseCount('failed_jobs', 7);

        Artisan::call('casa:renvoyer-echecs-smtp', ['--lot' => 3, '--pause' => 0]);

        // `queue:retry` remet chaque échec en file (`jobs`), il n'est PAS
        // ré-envoyé ici (pas de worker relancé) — donc 0 doublon possible et
        // la ligne quitte `failed_jobs` exactement une fois.
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('jobs', 7);
    }

    public function test_sans_echec_ne_fait_rien(): void
    {
        Artisan::call('casa:renvoyer-echecs-smtp');

        $this->assertStringContainsString('Aucun échec', Artisan::output());
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_ignore_un_echec_qui_n_est_pas_une_notification_mail(): void
    {
        // Ligne de `failed_jobs` qui ne correspond à AUCUN job notification
        // connu (payload illisible) — doit être ignorée, pas renvoyée.
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['data' => ['command' => 'CORROMPU']]),
            'exception' => 'Exception de test',
            'failed_at' => now(),
        ]);

        Artisan::call('casa:renvoyer-echecs-smtp', ['--dry-run' => true]);

        $this->assertStringContainsString('Aucun échec', Artisan::output());
        $this->assertDatabaseCount('failed_jobs', 1); // non touché
    }

    public function test_les_defauts_de_lot_et_pause_respectent_mail_max_per_minute(): void
    {
        // Débit par défaut (30/min) pendant la PRODUCTION des échecs : les 9
        // tentatives doivent toutes atteindre le transport (donc échouer),
        // aucune ne doit être retardée par le limiteur à ce stade.
        $this->produireEchecsMail(9);
        $this->assertDatabaseCount('failed_jobs', 9);

        // Le débit n'est abaissé qu'ENSUITE, pour piloter le lot par défaut
        // de la commande de renvoi (--lot omis : doit valoir
        // config('casa.mail_max_per_minute') = 4).
        config(['casa.mail_max_per_minute' => 4]);

        // --pause=0 pour ne pas ralentir le test (la valeur par défaut 60s
        // est documentée et vérifiée séparément par lecture du code/signature).
        // `$this->artisan()` (pas `Artisan::call` + `Artisan::output()`) :
        // la commande appelle elle-même `queue:retry` via `Artisan::call()`
        // en interne, ce qui écrase le tampon de sortie que `Artisan::output()`
        // renverrait au niveau englobant — `$this->artisan()` isole
        // correctement chaque assertion de sortie de cet effet de bord.
        $this->artisan('casa:renvoyer-echecs-smtp', ['--pause' => 0])
            ->expectsOutputToContain('Lot 1/3') // 9 / 4 = 3 lots
            ->assertExitCode(0);

        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('jobs', 9);
    }
}
