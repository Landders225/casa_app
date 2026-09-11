<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `notifications` (Lot 12c, canal `database` natif des
 * Notifications déjà écrites au Lot 12b, ADR-33). Stub standard Laravel
 * (`notifications:table`), avec UNE correction obligatoire : `uuidMorphs`
 * au lieu de `morphs` — le seul `Notifiable` du projet (`User`) a une PK
 * UUID (`HasUuids`, `$keyType = 'string'`) ; le stub par défaut crée une
 * clé `notifiable_id` en `bigint` auto-incrémenté, structurellement
 * incompatible avec `utilisateur.id` (uuid).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->uuidMorphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
