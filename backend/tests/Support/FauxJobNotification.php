<?php

namespace Tests\Support;

use Throwable;

/**
 * Double minimal du job `Illuminate\Notifications\SendQueuedNotifications`
 * pour tester `ToleranceSmtpTemporaire` en isolation, sans passer par une
 * vraie file (Lot 18). N'implémente QUE ce que lit le middleware :
 * `$notification`, `attempts()`, `release()`, `fail()`.
 */
class FauxJobNotification
{
    public object $notification;

    /** @var array<int, int> */
    public array $relances = [];

    public ?Throwable $echecDefinitif = null;

    public function __construct(object $notification, private int $tentative = 1)
    {
        $this->notification = $notification;
    }

    public function attempts(): int
    {
        return $this->tentative;
    }

    public function release(int $delai): void
    {
        $this->relances[] = $delai;
    }

    public function fail(Throwable $e): void
    {
        $this->echecDefinitif = $e;
    }
}
