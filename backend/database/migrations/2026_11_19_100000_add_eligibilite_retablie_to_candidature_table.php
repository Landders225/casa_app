<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — Lot 4 (post-Lot 3). `candidature.eligibilite_retablie` : mémorise
 * qu'un administrateur a explicitement rétabli l'éligibilité d'un dossier
 * (`POST /admin/candidatures/eligibilite/retablir`, ADR-49) — PRIORITAIRE sur
 * tout recalcul automatique ultérieur (`VerificationController`,
 * `CorrectionController`), tant qu'une élimination manuelle ne le remet pas à
 * `false` (seule action réversible, ADR-15).
 *
 * `eligibilite_retablie_le`/`_par` suivent le même patron que
 * `dossier_verrouille_le`/`_par` (migration `create_candidature_table`) :
 * traçabilité pure, jamais exposées par une Resource (même discipline).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidature', function (Blueprint $table) {
            $table->boolean('eligibilite_retablie')->default(false)->after('statut_eligibilite_interne');
            $table->timestampTz('eligibilite_retablie_le')->nullable()->after('eligibilite_retablie');
            $table->uuid('eligibilite_retablie_par')->nullable()->after('eligibilite_retablie_le');
            $table->foreign('eligibilite_retablie_par')->references('id')->on('membre_equipe');
        });
    }

    public function down(): void
    {
        Schema::table('candidature', function (Blueprint $table) {
            $table->dropForeign(['eligibilite_retablie_par']);
            $table->dropColumn(['eligibilite_retablie', 'eligibilite_retablie_le', 'eligibilite_retablie_par']);
        });
    }
};
