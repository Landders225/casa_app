<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `grille` (docs/mld.md §6, ADR-04). Une version du barème complet.
 * Index unique partiel : au plus une grille active à la fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grille', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->integer('version');
            $table->string('label', 150);
            $table->date('date_effet');
            $table->boolean('actif')->default(false);
            $table->timestampTz('created_at')->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX one_active_grille ON grille (actif) WHERE actif = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('grille');
    }
};
