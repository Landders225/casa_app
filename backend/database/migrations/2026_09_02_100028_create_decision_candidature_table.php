<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `decision_candidature` (docs/mld.md §7, ADR-03). 1-1 avec
 * `candidature`. `rang` et `motif_interne` sont 🔴 (jamais communiqués) ;
 * `decision` et `motif_communicable` sont 🟡 (visibles seulement si une
 * `publication` existe sur la campagne).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_candidature', function (Blueprint $table) {
            $table->uuid('candidature_id')->primary();
            $table->integer('rang');                          // 🔴
            $table->string('decision', 20);                   // 🟡
            $table->text('motif_interne')->nullable();        // 🔴
            $table->text('motif_communicable')->nullable();   // 🟡

            $table->foreign('candidature_id')->references('id')->on('candidature');
        });

        DB::statement("ALTER TABLE decision_candidature ADD CONSTRAINT decision_candidature_decision_check CHECK (decision IN ('retenu','liste_attente','non_retenu','indisponible'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_candidature');
    }
};
