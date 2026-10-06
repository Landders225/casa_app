<?php

namespace Tests\Support;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Faux transport SMTP EN MÉMOIRE (pas un vrai socket) pour piloter une
 * séquence de réponses dans les tests PHPUnit (Lot 18) : ex. `[421, 421, 0]`
 * simule deux rejets temporaires puis un envoi accepté (0 = succès).
 *
 * État STATIQUE délibérément : `Mail::extend()` construit cette classe via
 * un simple `fn () => new self`, sans moyen de lui injecter une référence
 * partagée — le compteur doit survivre à l'instanciation d'un nouveau
 * transport à chaque résolution du mailer.
 *
 * Ne couvre PAS la preuve « vrai worker » exigée séparément (retryUntil()
 * qui neutralise --tries=3 à travers des process `artisan queue:work --once`
 * RÉELLEMENT séparés) : impossible avec un état en mémoire PHP, qui ne
 * survit pas à un nouveau process — voir docs/DEPLOIEMENT.md §9.4 pour le
 * vrai faux serveur SMTP (socket réel) utilisé pour cette preuve-là.
 */
class TransportSmtpSimule extends AbstractTransport
{
    /** @var array<int, int> */
    private static array $codes = [];

    private static int $compteur = 0;

    /**
     * @param  array<int, int>  $codes  Code SMTP à renvoyer pour chaque appel
     *                                  successif (0 = succès). Au-delà de la
     *                                  séquence fournie, renvoie 0 (succès).
     */
    public static function programmer(array $codes): void
    {
        self::$codes = $codes;
        self::$compteur = 0;
    }

    public static function nombreAppels(): int
    {
        return self::$compteur;
    }

    protected function doSend(SentMessage $message): void
    {
        $code = self::$codes[self::$compteur] ?? 0;
        self::$compteur++;

        if ($code !== 0) {
            throw new TransportException("Simulation : rejet SMTP {$code}", $code);
        }
    }

    public function __toString(): string
    {
        return 'smtp-simule://test';
    }
}
