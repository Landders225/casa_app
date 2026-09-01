<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `campagne` (docs/mld.md §2). Une cohorte (ex. "Cohorte 1 — 2026").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campagne', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('nom', 150);
            $table->string('statut', 20);
            $table->date('date_ouverture');
            $table->date('date_cloture');
            $table->integer('places_totales');
            $table->text('description')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE campagne ADD CONSTRAINT campagne_statut_check CHECK (statut IN ('brouillon','ouverte','cloturee'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('campagne');
    }
};
