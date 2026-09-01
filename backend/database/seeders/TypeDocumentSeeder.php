<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Référentiel des 6 pièces du dossier, conforme à
 * App_maquette/assets/js/mock-data.js (DOC_TYPES).
 */
class TypeDocumentSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('type_document')->insert([
            ['code' => 'cni',       'libelle' => "Carte Nationale d'Identité"],
            ['code' => 'residence', 'libelle' => 'Certificat de résidence'],
            ['code' => 'diplome',   'libelle' => 'Diplôme / bulletin'],
            ['code' => 'cv',        'libelle' => 'Curriculum Vitae'],
            ['code' => 'lettre',    'libelle' => 'Lettre de motivation'],
            ['code' => 'photo',     'libelle' => "Photo d'identité"],
        ]);
    }
}
