<?php

namespace Tests\Feature\Security;

use App\Rules\PolitiqueMotDePasse;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Lot 15d — Have I Been Pwned (`Password::uncompromised()`, ADR — Points
 * ouverts R2). Teste le MÉCANISME une seule fois, contre `PolitiqueMotDePasse`
 * directement (source unique des 5 points d'entrée) : chaque point d'entrée
 * (inscription, changement candidat/équipe, mot de passe oublié, CLI équipe)
 * a SA PROPRE preuve de câblage dans son test dédié — ne pas dupliquer ici la
 * preuve du mécanisme 5 fois. `TestCase::fakerHibp()` (utilisé ci-dessous)
 * documente le piège de précédence Http::fake() découvert en écrivant ce
 * fichier.
 *
 * FAIL-OPEN NATIF à Laravel (`Illuminate\Validation\NotPwnedVerifier`), pas
 * recodé : une exception réseau OU une réponse non-2xx produisent toutes deux
 * un corps vide -> aucune correspondance -> mot de passe ACCEPTÉ. Testé ici
 * en isolant les deux chemins (exception ET 5xx), pas seulement l'un des deux.
 */
class HibpMotDePasseTest extends TestCase
{
    /**
     * Reconstitue la ligne « SUFFIXE:occurrences » que l'API HIBP renverrait
     * pour CE mot de passe précis (k-anonymat : seul le préfixe à 5
     * caractères du SHA1 est envoyé, l'API renvoie toutes les fins de hash
     * partageant ce préfixe).
     */
    private function ligneHibp(string $motDePasse, int $occurrences): string
    {
        $hash = strtoupper(sha1($motDePasse));

        return substr($hash, 5).':'.$occurrences;
    }

    public function test_mot_de_passe_connu_compromis_est_refuse(): void
    {
        $this->fakerHibp(['api.pwnedpasswords.com/*' => Http::response($this->ligneHibp('Password2024', 3_000_000))]);

        $validator = Validator::make(
            ['password' => 'Password2024'],
            ['password' => PolitiqueMotDePasse::regles()],
        );

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('compromis', $validator->errors()->first('password'));
    }

    public function test_mot_de_passe_non_compromis_est_accepte(): void
    {
        // Préfixe recherché ne contient AUCUNE ligne correspondant à ce mot de passe.
        $this->fakerHibp(['api.pwnedpasswords.com/*' => Http::response('')]);

        $validator = Validator::make(
            ['password' => 'UnMotDePasseValide9'],
            ['password' => PolitiqueMotDePasse::regles()],
        );

        $this->assertFalse($validator->fails());
    }

    public function test_service_hibp_injoignable_le_mot_de_passe_passe_quand_meme(): void
    {
        $this->fakerHibp(function () {
            throw new ConnectionException('Panne HIBP simulée — connexion impossible.');
        });

        $validator = Validator::make(
            ['password' => 'UnMotDePasseValide9'],
            ['password' => PolitiqueMotDePasse::regles()],
        );

        $this->assertFalse($validator->fails(), 'une panne réseau HIBP ne doit JAMAIS bloquer un mot de passe légitime');
    }

    public function test_reponse_5xx_de_hibp_le_mot_de_passe_passe_quand_meme(): void
    {
        $this->fakerHibp(['api.pwnedpasswords.com/*' => Http::response('', 503)]);

        $validator = Validator::make(
            ['password' => 'UnMotDePasseValide9'],
            ['password' => PolitiqueMotDePasse::regles()],
        );

        $this->assertFalse($validator->fails(), 'un 503 HIBP ne doit JAMAIS bloquer un mot de passe légitime — même chemin fail-open que la panne réseau');
    }

    public function test_le_timeout_hibp_est_resserre_a_3_secondes(): void
    {
        $verifier = $this->app->make(UncompromisedVerifier::class);

        $reflexion = new ReflectionProperty($verifier, 'timeout');
        $reflexion->setAccessible(true);

        $this->assertSame(3, $reflexion->getValue($verifier), 'AppServiceProvider doit rebinder le timeout à 3 s (30 s par défaut dans Laravel)');
    }
}
