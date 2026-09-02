<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CASA — Lot 5a (D-5a-4). `decision_candidature.rang` devient nullable.
 *
 * Un candidat `non_eligible` dont le dossier ET l'entretien sont validés reçoit
 * désormais une décision EXPLICITE `non_retenu` (+ `motif_interne = 'non éligible'`,
 * 🔴) plutôt que d'être absent du classement — pour qu'au Lot 5b tout candidat
 * ait un résultat déterminé après publication. Mais il n'est PAS classé : il n'a
 * donc pas de `rang` (les non-éligibles ne concourent pas pour les places).
 *
 * `rang` reste 🔴 et n'a de sens que pour les candidatures classables.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE decision_candidature ALTER COLUMN rang DROP NOT NULL');
    }

    public function down(): void
    {
        // Ne réussit que si aucune ligne n'a rang IS NULL.
        DB::statement('ALTER TABLE decision_candidature ALTER COLUMN rang SET NOT NULL');
    }
};
