<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `experience_professionnelle` (docs/mld.md §3). Table entière 🔴.
 * 0..n par candidature. Règle "1 expérience = 1 justificatif obligatoire" :
 * `piece_justificative_id` NOT NULL + unique (une pièce ne sert qu'à une seule
 * expérience).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experience_professionnelle', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('candidature_id');
            $table->string('domaine', 20);
            $table->string('duree_categorie', 10);
            $table->uuid('piece_justificative_id')->unique();
            $table->timestampTz('created_at')->nullable();

            $table->foreign('candidature_id')->references('id')->on('candidature');
            $table->foreign('piece_justificative_id')->references('id')->on('piece_justificative');
        });

        DB::statement("ALTER TABLE experience_professionnelle ADD CONSTRAINT experience_professionnelle_domaine_check CHECK (domaine IN ('hotellerie','restauration','commerce'))");
        DB::statement("ALTER TABLE experience_professionnelle ADD CONSTRAINT experience_professionnelle_duree_categorie_check CHECK (duree_categorie IN ('moins_6','6_12','plus_12'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('experience_professionnelle');
    }
};
