<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `evaluation_dossier` (docs/mld.md §4, ADR-04). Table entière 🔴.
 * `score_total` = snapshot /65 figé à la validation ; `grille_id` = version
 * exacte utilisée (jamais recalculé après verrouillage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_dossier', function (Blueprint $table) {
            $table->uuid('candidature_id')->primary();
            $table->uuid('grille_id');
            $table->decimal('score_total', 4, 1);
            $table->boolean('valide')->default(false);
            $table->timestampTz('valide_le')->nullable();
            $table->uuid('valide_par')->nullable();

            $table->foreign('candidature_id')->references('id')->on('candidature');
            $table->foreign('grille_id')->references('id')->on('grille');
            $table->foreign('valide_par')->references('id')->on('membre_equipe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_dossier');
    }
};
