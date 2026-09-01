<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `journal_audit` (docs/mld.md §8, ADR-12). Table entière 🔴.
 * APPEND-ONLY : l'immuabilité est garantie à deux niveaux —
 *  1) applicatif : aucune route/policy/contrôleur de modification (ADR-12) ;
 *  2) PostgreSQL : trigger `BEFORE UPDATE OR DELETE OR TRUNCATE` qui lève une
 *     exception (migration suivante ..._add_journal_audit_append_only_trigger).
 * `motif` n'est pas NOT NULL : certaines actions n'en portent pas (ex. Connexion).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_audit', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('auteur_id');
            $table->string('role', 20);
            $table->string('action', 150);
            $table->string('module', 100);
            $table->string('objet', 150)->nullable();
            $table->text('ancienne_valeur')->nullable();
            $table->text('nouvelle_valeur')->nullable();
            $table->text('motif')->nullable();
            $table->string('resultat', 100)->default('Succès');
            $table->timestampTz('horodatage')->default(DB::raw('now()'));

            $table->foreign('auteur_id')->references('id')->on('utilisateur');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_audit');
    }
};
