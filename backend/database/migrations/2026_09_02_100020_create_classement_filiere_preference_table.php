<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `classement_filiere_preference` (docs/mld.md §3). Ordre de
 * préférence exprimé par LE candidat (MO.03). 🟢 : ce n'est pas un classement
 * de candidats. Un rang unique par candidature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classement_filiere_preference', function (Blueprint $table) {
            $table->uuid('candidature_id');
            $table->uuid('filiere_id');
            $table->smallInteger('rang');

            $table->primary(['candidature_id', 'filiere_id']);
            $table->unique(['candidature_id', 'rang']);
            $table->foreign('candidature_id')->references('id')->on('candidature');
            $table->foreign('filiere_id')->references('id')->on('filiere');
        });

        DB::statement('ALTER TABLE classement_filiere_preference ADD CONSTRAINT classement_filiere_preference_rang_check CHECK (rang BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('classement_filiere_preference');
    }
};
