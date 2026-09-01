<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `note_sous_critere_entretien` (docs/mld.md §5, ADR-05).
 * Les 10 sous-notes d'entretien persistées individuellement (PRES.01-03,
 * REL.01-03, EO.01-03, MOE.01-03). FK vers `entretien(candidature_id)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('note_sous_critere_entretien', function (Blueprint $table) {
            $table->uuid('entretien_id');
            $table->uuid('sous_critere_id');
            $table->decimal('points_attribues', 3, 1);

            $table->primary(['entretien_id', 'sous_critere_id']);
            $table->foreign('entretien_id')->references('candidature_id')->on('entretien');
            $table->foreign('sous_critere_id')->references('id')->on('sous_critere_entretien');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('note_sous_critere_entretien');
    }
};
