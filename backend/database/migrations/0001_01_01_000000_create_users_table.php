<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * CASA — la table applicative des comptes est `utilisateur` (cf. docs/mld.md,
     * ADR-10) : elle est créée par la migration métier
     * `..._create_utilisateur_table`. La table `users` du squelette Laravel n'est
     * donc PAS créée ici. On conserve `password_reset_tokens` et `sessions`, dont
     * l'infrastructure Laravel/Sanctum SPA a besoin (SESSION_DRIVER=database,
     * cf. docs/ADR.md ADR-01). Le recâblage du modèle `App\Models\User` sur la
     * table `utilisateur` est traité au Lot 2 (authentification).
     */
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
