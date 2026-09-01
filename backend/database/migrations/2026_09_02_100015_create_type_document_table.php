<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `type_document` (docs/mld.md §3). Référentiel des 6 pièces du
 * dossier : cni, residence, diplome, cv, lettre, photo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('type_document', function (Blueprint $table) {
            $table->string('code', 20)->primary();
            $table->string('libelle', 150);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('type_document');
    }
};
