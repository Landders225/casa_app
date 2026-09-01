<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `volet` (docs/mld.md §6). Dossier /65, Entretien /35.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volet', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('grille_id');
            $table->string('code', 20);
            $table->string('label', 100);
            $table->decimal('max_points', 4, 1);

            $table->unique(['grille_id', 'code']);
            $table->foreign('grille_id')->references('id')->on('grille');
        });

        DB::statement("ALTER TABLE volet ADD CONSTRAINT volet_code_check CHECK (code IN ('dossier','entretien'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('volet');
    }
};
