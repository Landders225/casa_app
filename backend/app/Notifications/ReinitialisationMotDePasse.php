<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Lien de réinitialisation de mot de passe (Lot 13, ADR-32).
 *
 * `ShouldQueue` — passe par le worker (Lot 12a) : le contrôleur qui déclenche
 * l'envoi ne doit JAMAIS attendre le SMTP (et l'attente serait sinon un oracle
 * temporel révélant si l'adresse existe, cf. ADR-32).
 *
 * Contenu volontairement minimal : aucune donnée de compte (pas de nom, pas de
 * rôle) — seul le lien de réinitialisation, qui porte le VRAI secret (le
 * `$token`, jamais journalisé, cf. `User::sendPasswordResetNotification()`).
 * Cible la SPA (`APP_URL`), pas une route Laravel — il n'y a pas de vue web
 * `password.reset`.
 */
class ReinitialisationMotDePasse extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lien = $this->lien($notifiable);

        return (new MailMessage)
            ->subject('[CASA] Réinitialisation de votre mot de passe')
            ->view('emails.reinitialisation-mot-de-passe', [
                'appName' => config('app.name'),
                'lien' => $lien,
                'expireDansMinutes' => (int) config('auth.passwords.users.expire', 60),
            ]);
    }

    /**
     * Lien VERS LA SPA — pas une route Laravel (API-only, pas de vue `password.reset`).
     * `email` encodé pour porter les accents / `+` sans corruption.
     */
    private function lien(object $notifiable): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $email = urlencode((string) $notifiable->getEmailForPasswordReset());

        return "{$base}/mot-de-passe/nouveau?token={$this->token}&email={$email}";
    }
}
