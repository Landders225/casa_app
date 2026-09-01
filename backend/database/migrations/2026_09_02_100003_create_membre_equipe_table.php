<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `membre_equipe` (docs/mld.md §1). 1-1 avec `utilisateur`
 * (role = evaluateur OU administrateur). Le sous-type exact n'est pas dupliqué
 * ici : il est lu sur `utilisateur.role` (ADR-10). `initiales` est un dérivé,
 * non stocké.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membre_equipe', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('utilisateur_id')->unique();
            $table->string('prenom', 100);
            $table->string('nom', 100);
            $table->string('poste', 150);
            $table->timestampsTz();

            $table->foreign('utilisateur_id')->references('id')->on('utilisateur');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membre_equipe');
    }
};
