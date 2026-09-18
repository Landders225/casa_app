<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Référentiel des 7 pièces du dossier — les 6 conformes à
 * App_maquette/assets/js/mock-data.js (DOC_TYPES), + `cmu` ajouté au Lot 18
 * (hors maquette — demande projet, pas un portage).
 *
 * `upsert` (Lot 18) : corrige une inexactitude découverte en l'écrivant — le
 * commentaire de `SeedReferentiel::handle()` promettait déjà « idempotents :
 * firstOrCreate », mais ce seeder faisait un simple `insert`, qui aurait
 * échoué (clé dupliquée) sur une base où les 6 premières lignes existent déjà.
 * Avec `upsert`, `php artisan casa:seed-referentiel --force` devient le
 * chemin de mise à niveau en PRODUCTION pour ajouter `cmu` sans toucher aux
 * 6 lignes existantes (`update` sur `libelle` uniquement — inoffensif, les
 * libellés ci-dessous sont déjà les valeurs en place).
 */
class TypeDocumentSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('type_document')->upsert([
            ['code' => 'cni',       'libelle' => "Carte Nationale d'Identité"],
            ['code' => 'residence', 'libelle' => 'Certificat de résidence'],
            ['code' => 'diplome',   'libelle' => 'Diplôme / bulletin'],
            ['code' => 'cv',        'libelle' => 'Curriculum Vitae'],
            ['code' => 'lettre',    'libelle' => 'Lettre de motivation'],
            ['code' => 'photo',     'libelle' => "Photo d'identité"],
            ['code' => 'cmu',       'libelle' => 'Couverture Maladie Universelle (CMU)'],
        ], uniqueBy: ['code'], update: ['libelle']);
    }
}
