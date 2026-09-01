<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — Lot 2. Le squelette Laravel crée `sessions.user_id` en `bigint`
 * (`foreignId`), incompatible avec `utilisateur.id` (uuid) une fois
 * l'authentification recâblée (ADR-10). Le driver de session `database`
 * écrit alors un uuid dans une colonne bigint -> erreur SQL.
 *
 * On repasse la colonne en `uuid` nullable indexée. Les sessions sont
 * éphémères : aucune donnée à préserver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });

        Schema::table('sessions', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->index()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });

        Schema::table('sessions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->index()->after('id');
        });
    }
};
