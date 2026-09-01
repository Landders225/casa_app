<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `candidature` (docs/mld.md §3). Porte le statut interne
 * (jamais renvoyé brut au candidat, ADR-03). `statut_interne` /
 * `statut_eligibilite_interne` sont 🔴.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidature', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('candidat_id');
            $table->uuid('campagne_id');
            $table->uuid('filiere_id');
            $table->string('numero_dossier', 30)->unique();
            $table->string('statut_interne', 20)->default('brouillon');                 // 🔴
            $table->string('statut_eligibilite_interne', 20)->default('non_verifie');   // 🔴
            $table->timestampTz('date_soumission')->nullable();
            $table->boolean('dossier_verrouille')->default(false);                       // 🔴
            $table->timestampTz('dossier_verrouille_le')->nullable();                   // 🔴
            $table->uuid('dossier_verrouille_par')->nullable();                         // 🔴
            $table->uuid('evaluateur_id')->nullable();                                  // 🔴
            $table->text('commentaire_evaluateur')->nullable();                         // 🔴
            $table->date('date_evaluation')->nullable();                                // 🔴
            $table->boolean('cqp_confirme')->default(false);
            $table->timestampsTz();

            $table->index('candidat_id');
            $table->index(['campagne_id', 'filiere_id']);

            $table->foreign('candidat_id')->references('id')->on('candidat');
            $table->foreign('campagne_id')->references('id')->on('campagne');
            $table->foreign('filiere_id')->references('id')->on('filiere');
            $table->foreign('dossier_verrouille_par')->references('id')->on('membre_equipe');
            $table->foreign('evaluateur_id')->references('id')->on('membre_equipe');
        });

        DB::statement("ALTER TABLE candidature ADD CONSTRAINT candidature_statut_interne_check CHECK (statut_interne IN ('brouillon','soumis','en_instruction','non_eligible','evalue'))");
        DB::statement("ALTER TABLE candidature ADD CONSTRAINT candidature_statut_eligibilite_interne_check CHECK (statut_eligibilite_interne IN ('non_verifie','eligible','non_eligible'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('candidature');
    }
};
