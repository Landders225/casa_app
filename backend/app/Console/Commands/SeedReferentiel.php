<?php

namespace App\Console\Commands;

use Database\Seeders\CampagneSeeder;
use Database\Seeders\FiliereSeeder;
use Database\Seeders\GrilleBaremeSeeder;
use Database\Seeders\TypeDocumentSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Amorce le RÉFÉRENTIEL en production — SANS les comptes de démonstration.
 *
 *   php artisan casa:seed-referentiel
 *
 * `php artisan db:seed` (DatabaseSeeder) embarque `ComptesDemoSeeder`
 * (candidat@ / evaluateur@ / admin@casa-demo.ci, mot de passe public) : le
 * lancer en production créerait trois comptes à mot de passe connu — une faille.
 * Cette commande ne joue que les 4 seeders de référentiel :
 *   TypeDocument · Filiere · Campagne · GrilleBareme
 *
 * Le premier compte administrateur se crée ensuite avec `casa:create-admin`.
 */
class SeedReferentiel extends Command
{
    protected $signature = 'casa:seed-referentiel {--force : Ré-exécuter même si le référentiel semble déjà présent}';

    protected $description = 'Amorce le référentiel (types de doc, filières, campagne, grille) — sans les comptes démo';

    public function handle(): int
    {
        $dejaAmorce = DB::table('grille')->exists() || DB::table('filiere')->exists();

        if ($dejaAmorce && ! $this->option('force')) {
            $this->warn('Le référentiel semble déjà présent (table `grille` ou `filiere` non vide).');
            $this->line('Utilisez --force pour ré-exécuter les seeders (ils sont idempotents : firstOrCreate).');

            return self::SUCCESS;
        }

        foreach ([TypeDocumentSeeder::class, FiliereSeeder::class, CampagneSeeder::class, GrilleBaremeSeeder::class] as $seeder) {
            $this->components->task($seeder, fn () => $this->callSilent('db:seed', ['--class' => $seeder, '--force' => true]) === self::SUCCESS);
        }

        $this->newLine();
        $this->info('Référentiel amorcé. Créez maintenant le premier administrateur : php artisan casa:create-admin <email>');

        return self::SUCCESS;
    }
}
