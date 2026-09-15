<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — Lot 17. `campagne.classement_perime` : le classement PERSISTÉ
 * (`decision_candidature`, Lot 5a) devient périmé dès qu'un quota
 * (`campagne_filiere.quota`) est modifié APRÈS que ce classement ait été
 * calculé — tant qu'aucune `publication` n'existe (au-delà, l'édition de
 * quota est bloquée, cf. `CampagneController::modifierQuotas`).
 *
 * Volontairement un simple booléen, pas un rapprochement de timestamps :
 * `decision_candidature` n'a pas de colonne `updated_at` (`$timestamps =
 * false`, Lot 5a) et un booléen suffit à la seule question qui compte
 * « peut-on faire confiance à ce qui est affiché ? ». Remis à `false` par
 * `ClassementController::calculer()` dès qu'un recalcul explicite est fait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campagne', function (Blueprint $table) {
            $table->boolean('classement_perime')->default(false)->after('places_totales');
        });
    }

    public function down(): void
    {
        Schema::table('campagne', function (Blueprint $table) {
            $table->dropColumn('classement_perime');
        });
    }
};
