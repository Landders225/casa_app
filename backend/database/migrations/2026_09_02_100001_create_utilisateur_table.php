<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `utilisateur` (docs/mld.md §1). Identité de connexion commune aux
 * 3 rôles (Sanctum SPA, ADR-01). Le sous-type (candidat / membre_equipe) est
 * porté par les tables dédiées ; le rôle exact est lu ici (ADR-10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utilisateur', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('email', 255)->unique();
            $table->string('mot_de_passe_hash', 255);            // 🔴 jamais lu, seulement vérifié serveur
            $table->string('role', 20);
            $table->boolean('actif')->default(true);
            $table->timestampTz('cree_le')->default(DB::raw('now()'));
            $table->timestampTz('derniere_connexion_le')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE utilisateur ADD CONSTRAINT utilisateur_role_check CHECK (role IN ('candidat','evaluateur','administrateur'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('utilisateur');
    }
};
