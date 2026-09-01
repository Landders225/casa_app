<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `item` (docs/mld.md §6). Volet Dossier uniquement.
 * `type` : choix | derive | texte | texte_note | document | niveaux | classement
 * (aucun CHECK : `niveaux` et `classement` sont utilisés par scoring.js mais
 * absents de la liste indicative du MLD — cf. Étape 1, divergence D3).
 * `max_points` NULL quand l'item n'est pas noté (`notee = false`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('rubrique_id');
            $table->string('code', 20);
            $table->string('label', 255);
            $table->string('type', 20);
            $table->decimal('max_points', 4, 2)->nullable();
            $table->boolean('notation_evaluateur')->default(false);
            $table->boolean('notee')->default(true);
            $table->boolean('eliminatoire')->default(false);
            $table->string('eliminatoire_groupe', 30)->nullable();

            $table->unique(['rubrique_id', 'code']);
            $table->foreign('rubrique_id')->references('id')->on('rubrique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item');
    }
};
