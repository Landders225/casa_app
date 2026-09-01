<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `score_rubrique_dossier` (docs/mld.md §4). Snapshot du score par
 * rubrique (6 lignes par évaluation). FK vers `evaluation_dossier(candidature_id)`
 * (PK de cette table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_rubrique_dossier', function (Blueprint $table) {
            $table->uuid('evaluation_dossier_id');
            $table->uuid('rubrique_id');
            $table->decimal('score_obtenu', 4, 2);

            $table->primary(['evaluation_dossier_id', 'rubrique_id']);
            $table->foreign('evaluation_dossier_id')->references('candidature_id')->on('evaluation_dossier');
            $table->foreign('rubrique_id')->references('id')->on('rubrique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_rubrique_dossier');
    }
};
