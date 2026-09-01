<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Campagne "Cohorte 1 — 2026" + quotas par filière, conformes à
 * App_maquette/assets/js/mock-data.js (CASA_CAMPAGNES[camp-1], CASA_CQP.quotaParCohorte = 24).
 *
 * NB : mock-data.js contient aussi une "Cohorte 2" au statut `brouillon`. Elle
 * est hors périmètre de ce lot (instruction : "une campagne Cohorte 1") — signalée,
 * non seedée.
 */
class CampagneSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $campagneId = (string) Str::uuid();

        DB::table('campagne')->insert([
            'id' => $campagneId,
            'nom' => 'Cohorte 1 — 2026',
            'statut' => 'ouverte',
            'date_ouverture' => '2026-05-01',
            'date_cloture' => '2026-06-30',
            'places_totales' => 120,
            'description' => 'Première cohorte du dispositif CASA, 5 filières CQP.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Quota 24 par filière (5 x 24 = 120 = places_totales).
        $filiereIds = DB::table('filiere')->pluck('id');
        foreach ($filiereIds as $filiereId) {
            DB::table('campagne_filiere')->insert([
                'campagne_id' => $campagneId,
                'filiere_id' => $filiereId,
                'quota' => 24,
            ]);
        }
    }
}
