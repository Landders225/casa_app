<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `filiere_competence` (docs/mld.md §2). Normalise le tableau
 * `competences` de la maquette (compétences clés affichées publiquement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filiere_competence', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('filiere_id');
            $table->string('libelle', 150);
            $table->smallInteger('ordre')->default(0);

            $table->foreign('filiere_id')->references('id')->on('filiere');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filiere_competence');
    }
};
