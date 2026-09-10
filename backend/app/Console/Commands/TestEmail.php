<?php

namespace App\Console\Commands;

use App\Mail\TestEmail as TestEmailMailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * `php artisan casa:test-email {destinataire} {--queue}` (Lot 12a, ADR-31).
 *
 * Sert à ce que L'ADMINISTRATEUR valide SA configuration d'envoi sur le serveur
 * — nous n'avons pas ses identifiants SMTP.
 *
 *  - défaut : ENVOI IMMÉDIAT (synchrone). L'erreur exacte du transport
 *    (« Authentication failed », « Connection refused »…) s'affiche tout de
 *    suite → c'est le mode pour valider des identifiants SMTP.
 *  - `--queue` : met l'e-mail dans la file `database` → teste EN PLUS la chaîne
 *    worker (config → `jobs` → worker → SMTP). Retour = « mis en file » ;
 *    vérifier la réception, sinon `queue:failed` / logs du worker.
 *
 * Le message d'échec affiché provient de l'exception du transport Symfony et ne
 * contient jamais le mot de passe. La commande n'affiche JAMAIS `config('mail.
 * host')` / `password` — seulement `config('mail.default')` (= `smtp` / `log`).
 *
 * Codes de sortie : INVALID (2) e-mail mal formé ; FAILURE (1) échec d'envoi
 * synchrone ; SUCCESS (0).
 */
class TestEmail extends Command
{
    protected $signature = 'casa:test-email
        {destinataire : Adresse e-mail qui recevra le test}
        {--queue : Passer par la file d\'attente (teste aussi le worker) au lieu d\'un envoi immédiat}';

    protected $description = "Envoie un e-mail de test pour valider la configuration d'envoi";

    public function handle(): int
    {
        $destinataire = trim((string) $this->argument('destinataire'));

        $check = Validator::make(['email' => $destinataire], ['email' => ['required', 'email:rfc']]);
        if ($check->fails()) {
            $this->error("Adresse e-mail invalide : « {$destinataire} ».");

            return self::INVALID;
        }

        $transport = (string) config('mail.default');
        $mail = new TestEmailMailable($transport, now()->toDateTimeString());

        if ($transport === 'log') {
            $this->warn('MAIL_MAILER=log : l\'e-mail sera ÉCRIT DANS LES LOGS, pas envoyé. '
                .'Passez MAIL_MAILER=smtp (+ identifiants) pour un envoi réel.');
        }

        if ($this->option('queue')) {
            Mail::to($destinataire)->queue($mail);

            $this->info("E-mail de test mis en file pour « {$destinataire} » (transport : {$transport}).");
            $this->line('Le worker doit l\'envoyer sous quelques secondes.');
            $this->line('Rien reçu ? → `dcp logs worker` puis `dcp exec backend php artisan queue:failed`.');

            return self::SUCCESS;
        }

        try {
            Mail::to($destinataire)->send($mail);
        } catch (Throwable $e) {
            $this->error('Échec de l\'envoi : '.$e->getMessage());
            $this->line('Vérifiez MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD / MAIL_SCHEME '
                .'dans backend/.env.production, puis `dcp up -d --force-recreate backend worker`.');

            return self::FAILURE;
        }

        $this->info("E-mail de test envoyé à « {$destinataire} » (transport : {$transport}).");
        if ($transport !== 'log') {
            $this->line('Vérifiez la boîte de réception (et le dossier « indésirables »).');
        }

        return self::SUCCESS;
    }
}
