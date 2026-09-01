<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `candidat` (docs/mld.md §1). 1-1 avec `utilisateur` (role =
 * candidat). Champs déclarés par le candidat — jamais nationalité ni diplôme
 * (cf. `verification_dossier`, ADR-07). `photo_initiales` est un dérivé calculé
 * côté serveur : il n'est pas stocké (cf. dictionnaire-donnees.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidat', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('utilisateur_id')->unique();
            $table->string('prenom', 100);
            $table->string('nom', 100);
            $table->char('sexe', 1);
            $table->date('date_naissance');
            $table->string('cni', 50);
            $table->string('telephone', 20);
            $table->string('ville_residence', 100);
            $table->boolean('residence_ci');
            $table->timestampsTz();

            $table->foreign('utilisateur_id')->references('id')->on('utilisateur');
        });

        DB::statement("ALTER TABLE candidat ADD CONSTRAINT candidat_sexe_check CHECK (sexe IN ('F','H'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('candidat');
    }
};
