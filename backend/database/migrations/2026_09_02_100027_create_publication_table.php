<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `publication` (docs/mld.md §7, ADR-08). 1-1 avec `campagne`
 * (une campagne = au plus une publication). Son existence rend la décision
 * visible du candidat (StatutPublicResolver, ADR-03). `publiee_par` est 🔴.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publication', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('campagne_id')->unique();
            $table->timestampTz('publiee_le')->default(DB::raw('now()'));
            $table->uuid('publiee_par');

            $table->foreign('campagne_id')->references('id')->on('campagne');
            $table->foreign('publiee_par')->references('id')->on('membre_equipe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication');
    }
};
