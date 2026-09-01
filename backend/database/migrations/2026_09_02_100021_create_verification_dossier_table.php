<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `verification_dossier` (docs/mld.md §4, ADR-07). Table entière 🔴.
 * SEULE source de vérité pour nationalité / diplôme — jamais auto-déclarés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_dossier', function (Blueprint $table) {
            $table->uuid('candidature_id')->primary();
            $table->boolean('nationalite_confirmee')->nullable();
            $table->string('diplome_verifie', 10)->nullable();
            $table->uuid('verifie_par')->nullable();
            $table->timestampTz('verifie_le')->nullable();

            $table->foreign('candidature_id')->references('id')->on('candidature');
            $table->foreign('verifie_par')->references('id')->on('membre_equipe');
        });

        DB::statement("ALTER TABLE verification_dossier ADD CONSTRAINT verification_dossier_diplome_verifie_check CHECK (diplome_verifie IN ('cepe','cap','bepc','bac','bt_bep'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_dossier');
    }
};
