<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\InscriptionConfirmee;
use App\Notifications\ReinitialisationMotDePasse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TransportSmtpSimule;
use Tests\TestCase;

/**
 * Lot 18bis (ADR-36) — les e-mails CRITIQUES passent avant les INFORMATIFS,
 * même arrivés APRÈS dans la file (--queue=mail-critique,mail-information,
 * default : Laravel vide une file avant de regarder la suivante, PAS de
 * round-robin — Illuminate\Queue\Worker, vérifié dans le vendor).
 */
class PrioriteFilesMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_critique_arrive_en_dernier_est_envoye_avant_les_informatifs_plus_anciens(): void
    {
        TransportSmtpSimule::programmer([]); // toujours succès (code 0 par défaut)
        Mail::extend('smtp-simule', fn () => new TransportSmtpSimule);
        config([
            'mail.mailers.smtp-simule' => ['transport' => 'smtp-simule'],
            'mail.default' => 'smtp-simule',
            'queue.default' => 'database',
            // Un seul envoi autorisé par la fenêtre courante : force le
            // choix entre le critique et les informatifs. Les informatifs
            // sont dispatchés D'ABORD (jobs.id plus bas, plus anciens), le
            // critique APRÈS (jobs.id plus haut) — si le critique passe
            // quand même en premier, c'est bien la PRIORITÉ DE FILE qui
            // joue, pas l'ordre d'arrivée.
            'casa.mail_max_per_minute' => 1,
        ]);

        // 3 informatifs dispatchés D'ABORD (plus anciens).
        foreach (User::factory()->count(3)->create() as $destinataire) {
            $destinataire->notify(new InscriptionConfirmee);
        }

        // 1 critique dispatché APRÈS (plus récent).
        User::factory()->create()->notify(new ReinitialisationMotDePasse('jeton-de-test'));

        // 3 x (mail-information + database) + 1 (mail-critique seul).
        $this->assertDatabaseCount('jobs', 7);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'mail-critique')->count());
        $this->assertSame(3, DB::table('jobs')->where('queue', 'mail-information')->count());

        Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--tries' => 3,
            '--backoff' => 10,
            '--queue' => 'mail-critique,mail-information,default',
        ]);

        // Le limiteur (1/min, partagé) n'a laissé passer QU'UN SEUL envoi
        // mail au total — et c'est le critique, malgré son arrivée plus
        // tardive : sa file est vidée avant même de regarder
        // mail-information.
        $this->assertSame(1, TransportSmtpSimule::nombreAppels());
        $this->assertSame(0, DB::table('jobs')->where('queue', 'mail-critique')->count());
        $this->assertSame(3, DB::table('jobs')->where('queue', 'mail-information')->count());

        // Le canal database (channel indépendant, pas concerné par le
        // limiteur mail) continue de fonctionner normalement pour les 3
        // informatifs, qu'ils aient été envoyés par mail ou non.
        $this->assertDatabaseCount('notifications', 3);
    }
}
