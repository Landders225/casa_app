<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;

/**
 * Crée la base de test PostgreSQL `casa_test` de façon REPRODUCTIBLE.
 *
 *   docker compose exec backend php artisan casa:test-db
 *   docker compose exec backend php artisan casa:test-db --fresh
 *
 * Utilisée par la suite de tests (phpunit.xml → DB_DATABASE=casa_test). Le
 * schéma est ensuite appliqué par `RefreshDatabase` (migrations réelles, donc
 * fidélité Postgres : trigger append-only, CHECK, uuid).
 */
class CreateTestDatabase extends Command
{
    protected $signature = 'casa:test-db {--fresh : Supprime la base puis la recrée}';

    protected $description = 'Crée la base de données de test PostgreSQL (casa_test) si absente';

    public function handle(): int
    {
        $connexion = config('database.connections.pgsql');
        $baseTest = env('DB_TEST_DATABASE', 'casa_test');

        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=postgres', $connexion['host'], $connexion['port']),
            $connexion['username'],
            $connexion['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $existe = (bool) $pdo->query(
            'SELECT 1 FROM pg_database WHERE datname = '.$pdo->quote($baseTest)
        )->fetchColumn();

        if ($existe && $this->option('fresh')) {
            $pdo->exec(sprintf('DROP DATABASE "%s"', $baseTest));
            $this->warn("Base « {$baseTest} » supprimée.");
            $existe = false;
        }

        if ($existe) {
            $this->info("Base « {$baseTest} » déjà présente — rien à faire.");

            return self::SUCCESS;
        }

        $pdo->exec(sprintf('CREATE DATABASE "%s" OWNER "%s"', $baseTest, $connexion['username']));
        $this->info("Base « {$baseTest} » créée.");

        return self::SUCCESS;
    }
}
