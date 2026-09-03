<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CASA — Lot 6b (D-6b-3). `critere_eliminatoire_declenche.origine` gagne la
 * valeur `'decision_administrative'`, pour l'ÉLIMINATION MANUELLE : l'admin
 * force un dossier `non_eligible` avec motif. On ne réutilise PAS
 * `'verification_evaluateur'` (ça mentirait sur l'origine et polluerait
 * audit/stats — la fidélité de l'audit prime sur l'économie d'une migration).
 *
 * docs/mld.md mis à jour en conséquence.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE critere_eliminatoire_declenche DROP CONSTRAINT critere_eliminatoire_declenche_origine_check');
        DB::statement("ALTER TABLE critere_eliminatoire_declenche ADD CONSTRAINT critere_eliminatoire_declenche_origine_check CHECK (origine IN ('soumission_candidat','verification_evaluateur','decision_administrative'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE critere_eliminatoire_declenche DROP CONSTRAINT critere_eliminatoire_declenche_origine_check');
        DB::statement("ALTER TABLE critere_eliminatoire_declenche ADD CONSTRAINT critere_eliminatoire_declenche_origine_check CHECK (origine IN ('soumission_candidat','verification_evaluateur'))");
    }
};
