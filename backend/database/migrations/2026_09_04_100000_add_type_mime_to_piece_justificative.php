<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — Lot 3b. `piece_justificative` (MLD Lot 0) ne mémorise pas le type MIME.
 * Il est requis pour servir le fichier avec le bon `Content-Type` au
 * téléchargement sans re-scanner le contenu à chaque requête.
 *
 * Ajout de `type_mime` (NOT NULL — la table est vide ; toujours renseigné par
 * l'application à partir du MIME détecté par contenu à l'upload).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('piece_justificative', function (Blueprint $table) {
            $table->string('type_mime', 100)->after('taille_octets');
        });
    }

    public function down(): void
    {
        Schema::table('piece_justificative', function (Blueprint $table) {
            $table->dropColumn('type_mime');
        });
    }
};
