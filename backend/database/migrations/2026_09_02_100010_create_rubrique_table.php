<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `rubrique` (docs/mld.md §6). `max_points` = poids officiel de la
 * rubrique tel qu'énoncé dans App_maquette/assets/js/scoring.js (`poids`) :
 * Dossier 12/13/5/10/15/10, Entretien 8/10/8/9.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rubrique', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('volet_id');
            $table->string('code', 30);
            $table->string('label', 150);
            $table->decimal('max_points', 4, 1);
            $table->smallInteger('ordre')->default(0);

            $table->unique(['volet_id', 'code']);
            $table->foreign('volet_id')->references('id')->on('volet');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rubrique');
    }
};
