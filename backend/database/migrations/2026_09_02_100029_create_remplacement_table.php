<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `remplacement` (docs/mld.md §7). Table entière 🔴. Trace un
 * remplacement liste d'attente -> retenu. `candidature_promue_id` nullable :
 * il peut n'y avoir aucun candidat à promouvoir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remplacement', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('candidature_indisponible_id');
            $table->uuid('candidature_promue_id')->nullable();
            $table->text('motif');
            $table->uuid('effectue_par');
            $table->timestampTz('effectue_le')->default(DB::raw('now()'));

            $table->foreign('candidature_indisponible_id')->references('id')->on('candidature');
            $table->foreign('candidature_promue_id')->references('id')->on('candidature');
            $table->foreign('effectue_par')->references('id')->on('membre_equipe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remplacement');
    }
};
