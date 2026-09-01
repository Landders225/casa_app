<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * CASA — Lot 1. Données de référence uniquement (schéma + référentiel), aucune
 * donnée métier (candidatures, évaluations...). Ordre = dépendances FK.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TypeDocumentSeeder::class,
            FiliereSeeder::class,
            CampagneSeeder::class,
            GrilleBaremeSeeder::class,
            ComptesDemoSeeder::class,
        ]);
    }
}
