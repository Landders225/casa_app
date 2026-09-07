<?php

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * DURCISSEMENT DE CONFIGURATION (Lot 10) — garde exécutable sur les fichiers
 * livrés. Ces valeurs ne sont pas testables « à chaud » (elles vivent dans
 * l'environnement de prod), mais on peut garantir que les MODÈLES committés ne
 * régressent pas.
 *
 * Selon le contexte d'exécution, la racine du dépôt (avec `docker/`, `.gitignore`)
 * n'est pas toujours montée à côté du code (ex. suite lancée dans le conteneur
 * backend, qui ne voit que `backend/`). Les assertions qui en dépendent sont
 * alors `markTestSkipped` plutôt que fausses ; la CI (`.github/workflows/ci.yml`)
 * les rejoue sur le checkout complet via un grep dédié.
 */
class ConfigDurcieTest extends TestCase
{
    /** Racine du backend (contient `artisan`). */
    private function backend(): string
    {
        return dirname(__DIR__, 3);
    }

    /** Racine du dépôt (contient `docker/`), ou null si non montée ici. */
    private function depot(): ?string
    {
        $d = $this->backend();
        for ($i = 0; $i < 4; $i++) {
            if (is_dir($d.'/docker/nginx')) {
                return $d;
            }
            $d = dirname($d);
        }

        return null;
    }

    private function lireBackend(string $rel): string
    {
        $abs = $this->backend().'/'.$rel;
        $this->assertFileExists($abs);

        return file_get_contents($abs);
    }

    private function lireDepot(string $rel): string
    {
        $racine = $this->depot();
        if ($racine === null || ! is_file($racine.'/'.$rel)) {
            $this->markTestSkipped("Racine du dépôt non disponible ici — {$rel} vérifié en CI.");
        }

        return file_get_contents($racine.'/'.$rel);
    }

    public function test_env_production_exemple_est_durci(): void
    {
        $env = $this->lireBackend('.env.production.example');

        $this->assertMatchesRegularExpression('/^APP_ENV=production$/m', $env);
        $this->assertMatchesRegularExpression('/^APP_DEBUG=false$/m', $env);
        $this->assertMatchesRegularExpression('/^BCRYPT_ROUNDS=12$/m', $env);
        $this->assertMatchesRegularExpression('/^SESSION_SECURE_COOKIE=true$/m', $env);
        $this->assertMatchesRegularExpression('/^SESSION_SAME_SITE=lax$/m', $env);
        $this->assertMatchesRegularExpression('/^SESSION_HTTP_ONLY=true$/m', $env);
        // Aucune vraie clé committée (placeholder vide uniquement).
        $this->assertDoesNotMatchRegularExpression('/^APP_KEY=base64:.+/m', $env);
    }

    public function test_cors_n_autorise_pas_toutes_les_origines(): void
    {
        $cors = $this->lireBackend('config/cors.php');
        $this->assertStringNotContainsString("'allowed_origins' => ['*']", $cors);
        $this->assertMatchesRegularExpression("/'allowed_origins'\s*=>\s*\[\s*\n?\s*env\(/", $cors);
    }

    public function test_bcrypt_par_defaut_a_12_tours(): void
    {
        // config/hashing.php n'est pas publié -> valeur par défaut du framework :
        // rounds = env('BCRYPT_ROUNDS', 12). Les deux .env exemples portent 12.
        $this->assertSame('12', trim((string) (preg_match('/^BCRYPT_ROUNDS=(\d+)$/m', $this->lireBackend('.env.example'), $m) ? $m[1] : '')));
    }

    public function test_les_env_de_prod_sont_gitignores(): void
    {
        $gitignore = $this->lireDepot('.gitignore');
        $this->assertStringContainsString('.env.production', $gitignore);
    }

    public function test_php_ne_revele_pas_sa_version(): void
    {
        $ini = $this->lireDepot('docker/php/casa.ini');
        $this->assertMatchesRegularExpression('/^\s*expose_php\s*=\s*Off\s*$/mi', $ini);
    }

    public function test_nginx_ne_revele_pas_sa_version(): void
    {
        foreach (['docker/nginx/casa.prod.conf', 'docker/nginx/default.conf'] as $conf) {
            $this->assertMatchesRegularExpression('/server_tokens\s+off\s*;/', $this->lireDepot($conf));
        }
    }

    public function test_les_six_entetes_de_securite_sont_dans_la_conf_prod(): void
    {
        $conf = $this->lireDepot('docker/nginx/casa.prod.conf');
        foreach ([
            'Strict-Transport-Security',
            'X-Content-Type-Options',
            'X-Frame-Options',
            'Referrer-Policy',
            'Permissions-Policy',
            'Content-Security-Policy',
        ] as $entete) {
            $this->assertStringContainsString($entete, $conf, "En-tête {$entete} absent de casa.prod.conf");
        }
        $this->assertStringNotContainsString("'unsafe-eval'", $conf);
        $this->assertMatchesRegularExpression("/script-src 'self'/", $conf);
    }
}
