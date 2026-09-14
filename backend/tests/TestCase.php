<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * HIBP (Lot 15d, `Password::uncompromised()`) : par défaut, TOUS les
     * tests reçoivent un corps vide de `api.pwnedpasswords.com` — « aucune
     * correspondance », mot de passe accepté — au lieu d'un VRAI appel réseau
     * sortant à chaque test qui crée un compte (inscription, changement de
     * mot de passe, CLI équipe...). Un test qui veut un AUTRE comportement
     * HIBP doit passer par `$this->fakerHibp()` ci-dessous, pas par un second
     * `Http::fake()` direct — voir son docblock pour le piège que ça évite.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
    }

    /**
     * Reconfigure le comportement HIBP pour la suite du test EN COURS, à la
     * place du défaut posé par `setUp()` — pas en plus de lui.
     *
     * `Illuminate\Http\Client\Factory` est bindé SINGLETON (`AppServiceProvider`,
     * requis pour que `NotPwnedVerifier` — resté côté framework — partage la
     * MÊME instance que celle que `Http::fake()` configure ; sans ce binding,
     * `NotPwnedVerifier` reçoit sa propre instance non-stubée et tape le VRAI
     * réseau). Un singleton container survit à `Http::clearResolvedInstance()`
     * (qui n'efface QUE le cache de la façade) — il faut donc AUSSI l'oublier
     * du conteneur pour repartir d'une instance neuve : sans ça, le stub posé
     * par `setUp()` (premier enregistré) gagne TOUJOURS sur un second
     * `Http::fake()` pour la même URL (`Factory::buildStubHandler()` prend le
     * PREMIER callback qui matche, jamais le dernier). Piège vérifié en
     * écrivant `HibpMotDePasseTest` — pas une prudence de principe.
     */
    protected function fakerHibp(mixed $callback): void
    {
        $this->app->forgetInstance(HttpFactory::class);
        Http::clearResolvedInstance();
        Http::fake($callback);
    }

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
