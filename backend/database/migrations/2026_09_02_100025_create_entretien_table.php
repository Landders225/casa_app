<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `entretien` (docs/mld.md §5). Table entière 🔴. 1-1 avec
 * `candidature`. N'existe qu'une fois le dossier verrouillé. `score_total`
 * snapshot /35 ; `lieu` ∈ {Le Plateau, 2 Plateaux Vallons}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entretien', function (Blueprint $table) {
            $table->uuid('candidature_id')->primary();
            $table->string('statut', 20);
            $table->date('date');
            $table->time('heure');
            $table->string('lieu', 100);
            $table->uuid('evaluateur_id');
            $table->string('presence', 10)->nullable();
            $table->text('observation')->nullable();
            $table->uuid('grille_id')->nullable();
            $table->decimal('score_total', 4, 1)->nullable();
            $table->timestampTz('valide_le')->nullable();
            $table->uuid('valide_par')->nullable();

            $table->foreign('candidature_id')->references('id')->on('candidature');
            $table->foreign('evaluateur_id')->references('id')->on('membre_equipe');
            $table->foreign('grille_id')->references('id')->on('grille');
            $table->foreign('valide_par')->references('id')->on('membre_equipe');
        });

        DB::statement("ALTER TABLE entretien ADD CONSTRAINT entretien_statut_check CHECK (statut IN ('planifie','realise','valide'))");
        DB::statement("ALTER TABLE entretien ADD CONSTRAINT entretien_presence_check CHECK (presence IN ('present','absent'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('entretien');
    }
};
