<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * En-tête envoyé par toute requête du SPA React servi same-origin.
     * Sanctum n'active la session (EnsureFrontendRequestsAreStateful) que pour
     * les requêtes portant un Origin/Referer d'un domaine « stateful » (ADR-01).
     * Un navigateur l'ajoute toujours ; en test on le simule.
     */
    protected function fromSpa(): static
    {
        $this->withHeader('Origin', config('app.url'));

        return $this;
    }
}
