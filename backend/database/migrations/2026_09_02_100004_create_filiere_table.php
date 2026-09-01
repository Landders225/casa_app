<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `filiere` (docs/mld.md §2). Les 5 CQP. Catalogue stable
 * (pas de CRUD libre côté admin dans la maquette).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filiere', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('code', 50)->unique();
            $table->string('nom', 150);
            $table->text('description');
            $table->string('icone', 50)->nullable();
            $table->boolean('actif')->default(true);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filiere');
    }
};
