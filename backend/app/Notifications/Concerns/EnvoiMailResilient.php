<?php

namespace App\Notifications\Concerns;

use App\Notifications\Middleware\ToleranceSmtpTemporaire;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Résilience de l'envoi SMTP (Lot 18, ADR-35) — à utiliser par les 7 classes
 * `App\Notifications\*` (toutes `ShouldQueue`).
 *
 * - `middleware()` : débit (`envoi-mail-notification`, cf.
 *   `config('casa.mail_max_per_minute')`) + tolérance aux 421/4xx
 *   (`ToleranceSmtpTemporaire`) — appliqués UNIQUEMENT au canal `mail`.
 *   Laravel dispatche nativement un job PAR CANAL PAR DESTINATAIRE
 *   (`Illuminate\Notifications\NotificationSender::queueNotification`), et
 *   transmet le `$channel` exact à `middleware($notifiable, $channel)` : le
 *   canal `database` n'est donc jamais concerné, sans code de filtrage côté
 *   base de données à écrire.
 *
 * - `retryUntil()` : fenêtre de 6h — élargie pour laisser le temps à un
 *   incident SMTP de se résoudre (ex. Exchange qui sature sous 200
 *   inscriptions simultanées). ⚠️ Contrairement à `middleware()`, Laravel
 *   n'offre PAS de variante par canal pour `retryUntil()` (vérifié dans
 *   `Illuminate\Notifications\SendQueuedNotifications::retryUntil()` : appelé
 *   sans argument `$channel`) — la fenêtre s'applique donc AUSSI au job
 *   `database` de la même notification. Sans impact pratique : ce canal
 *   écrit en local (jamais de 421) et réussit normalement au premier essai.
 *   Preuve réelle (pas seulement `Carbon::setTestNow`) que cette fenêtre
 *   neutralise bien le `--tries=3` du worker de production :
 *   `docs/DEPLOIEMENT.md` §9.4.
 *
 * - `backoff()` : filet de sécurité à 30s pour toute défaillance qui NE PASSE
 *   PAS par `ToleranceSmtpTemporaire` (donc jamais réellement classifiée) —
 *   ex. une commande sérialisée corrompue, une exception PHP sans rapport
 *   avec le SMTP. ⚠️ Sans ce plancher, `retryUntil()` fait ignorer `--tries`
 *   À TOUTE exception du job (pas seulement les 421/4xx que notre middleware
 *   gère explicitement), et le délai de relance « générique » de Laravel
 *   (`$options->backoff`, 0 par défaut si l'appelant ne le précise pas) tombe
 *   à 0 — boucle de relance immédiate à chaud, mémoire épuisée en quelques
 *   secondes (reproduit en conditions réelles : `queue:work --stop-when-empty`
 *   sur un job dont la commande est volontairement corrompue — OOM avant ce
 *   correctif, cf. `ResultatsPubliesNotificationTest`). N'affecte PAS le délai
 *   explicite posé par `ToleranceSmtpTemporaire::handle()` via `$job->release()`,
 *   qui reste prioritaire pour le cas SMTP réellement géré.
 *
 * - `viaQueues()` (Lot 18bis) : route le job du canal `mail` vers la file
 *   `mail-critique` (ReinitialisationMotDePasse, MotDePasseModifie — chacune
 *   override `fileMail()`) ou `mail-information` (les 5 autres, défaut de
 *   `fileMail()` ci-dessous) — JAMAIS le canal `database`, qui reste sur sa
 *   file habituelle (`default`). Le worker traite les files dans l'ordre
 *   `mail-critique,mail-information,default` (cf. docker-compose.prod.yml) :
 *   Laravel vide une file avant de regarder la suivante (PAS de round-robin,
 *   vérifié dans `Illuminate\Queue\Worker`), donc un critique en attente
 *   passe toujours avant un informatif. Le LIMITEUR DE DÉBIT reste UNIQUE et
 *   PARTAGÉ entre les deux files (même nom `envoi-mail-notification` dans
 *   `middleware()` ci-dessus, quelle que soit la file) : deux limiteurs
 *   indépendants cumuleraient leurs débits et dépasseraient la vraie limite
 *   du serveur — un seul budget, auquel le critique accède prioritairement
 *   du simple fait de l'ordre de lecture des files par le worker.
 *
 * - `canauxInformatifs()` (Lot 18bis) : à appeler depuis le `via()` des 5
 *   classes INFORMATIVES (jamais par les 2 critiques, dont le `via()` ne la
 *   consulte pas) — retire le canal `mail` quand
 *   `casa.mail_notifications_informatives` est à `false` (ex. coupure
 *   temporaire pendant une inscription de masse), le canal `database` n'est
 *   JAMAIS retiré.
 */
trait EnvoiMailResilient
{
    /**
     * @return array<int, object>
     */
    public function middleware(object $notifiable, string $channel): array
    {
        if ($channel !== 'mail') {
            return [];
        }

        return [
            new RateLimited('envoi-mail-notification'),
            new ToleranceSmtpTemporaire,
        ];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function backoff(): int
    {
        return 30;
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['mail' => $this->fileMail()];
    }

    protected function fileMail(): string
    {
        return 'mail-information';
    }

    /**
     * @return array<int, string>
     */
    protected function canauxInformatifs(): array
    {
        return config('casa.mail_notifications_informatives')
            ? ['mail', 'database']
            : ['database'];
    }
}
