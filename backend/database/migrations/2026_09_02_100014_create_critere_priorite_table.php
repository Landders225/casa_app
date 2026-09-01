<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `critere_priorite` (docs/mld.md §6). Ordre de départage à
 * égalité : mixité -> vulnérabilité (NEET) -> expérience secteur -> motivation
 * (App_maquette/assets/js/scoring.js, CASA_CRITERES_PRIORITE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('critere_priorite', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('grille_id');
            $table->smallInteger('ordre');
            $table->string('code', 30);
            $table->string('label', 150);

            $table->unique(['grille_id', 'ordre']);
            $table->foreign('grille_id')->references('id')->on('grille');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('critere_priorite');
    }
};
