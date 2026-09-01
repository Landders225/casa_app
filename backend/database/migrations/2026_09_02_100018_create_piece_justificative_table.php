<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `piece_justificative` (docs/mld.md §3, ADR-11).
 *
 * Colonne discriminante `rattachement` (finalisation prévue par le MLD :
 * "trigger ou colonne discriminante") + CHECK conditionnel garantissant
 * l'exclusivité dossier XOR expérience :
 *   - rattachement = 'dossier'    => candidature_id ET type_document_code NON NULL
 *   - rattachement = 'experience' => candidature_id ET type_document_code NULL
 *     (le rattachement effectif se fait via experience_professionnelle.piece_justificative_id)
 *
 * `chemin_stockage` est 🔴 : hors webroot, jamais une URL publique (ADR-11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('piece_justificative', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('candidature_id')->nullable();
            $table->string('type_document_code', 20)->nullable();
            $table->string('rattachement', 12);
            $table->string('nom_original', 255);
            $table->string('chemin_stockage', 500);        // 🔴 hors webroot
            $table->integer('taille_octets');
            $table->timestampTz('depose_le')->default(DB::raw('now()'));

            $table->foreign('candidature_id')->references('id')->on('candidature');
            $table->foreign('type_document_code')->references('code')->on('type_document');
        });

        DB::statement("ALTER TABLE piece_justificative ADD CONSTRAINT piece_justificative_rattachement_check CHECK (rattachement IN ('dossier','experience'))");

        DB::statement(<<<'SQL'
            ALTER TABLE piece_justificative ADD CONSTRAINT piece_rattachee_dossier_xor_experience CHECK (
                (rattachement = 'dossier'    AND candidature_id IS NOT NULL AND type_document_code IS NOT NULL)
             OR (rattachement = 'experience' AND candidature_id IS NULL     AND type_document_code IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('piece_justificative');
    }
};
