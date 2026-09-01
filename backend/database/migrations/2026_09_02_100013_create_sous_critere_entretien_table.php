<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `sous_critere_entretien` (docs/mld.md §6). Volet Entretien
 * uniquement (PRES.01..., REL.01..., EO.01..., MOE.01...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sous_critere_entretien', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('rubrique_id');
            $table->string('code', 20);
            $table->string('label', 255);
            $table->decimal('max_points', 3, 1);

            $table->unique(['rubrique_id', 'code']);
            $table->foreign('rubrique_id')->references('id')->on('rubrique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sous_critere_entretien');
    }
};
