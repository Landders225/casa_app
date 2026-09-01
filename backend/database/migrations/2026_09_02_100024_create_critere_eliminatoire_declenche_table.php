<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `critere_eliminatoire_declenche` (docs/mld.md §4, ADR-06).
 * Trace, par candidature, les critères éliminatoires effectivement déclenchés
 * (miroir de `motifsElimination` de la maquette). La logique d'élimination
 * elle-même est codée serveur (ServiceEligibilite), pas dans cette table.
 * `code_critere` : texte libre (ex. 'DI.01', 'acces_sites', 'age_min').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('critere_eliminatoire_declenche', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('candidature_id');
            $table->string('code_critere', 50);
            $table->text('detail');
            $table->string('origine', 30);
            $table->timestampTz('declenche_le')->default(DB::raw('now()'));

            $table->foreign('candidature_id')->references('id')->on('candidature');
        });

        DB::statement("ALTER TABLE critere_eliminatoire_declenche ADD CONSTRAINT critere_eliminatoire_declenche_origine_check CHECK (origine IN ('soumission_candidat','verification_evaluateur'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('critere_eliminatoire_declenche');
    }
};
