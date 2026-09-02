<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CASA — Lot 4b (D-4b-1). `score_rubrique_dossier.score_obtenu` passait en
 * `numeric(4,2)` (MLD Lot 0). Or le portage fidèle de `scoring.js`
 * (`computeScores`) produit pour les rubriques `experience` et `langues` des
 * décimales périodiques (ex. 6/9×10 = 6,6667 — la preuve du rééchelonnage
 * /9→/10). On élargit à `numeric(6,4)` pour figer le détail par rubrique sans
 * perte. `evaluation_dossier.score_total` reste `numeric(4,1)` : il est arrondi
 * à une décimale, exactement comme `scoring.js` (`Math.round(total*10)/10`).
 *
 * docs/mld.md mis à jour en conséquence.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE score_rubrique_dossier ALTER COLUMN score_obtenu TYPE numeric(6,4)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE score_rubrique_dossier ALTER COLUMN score_obtenu TYPE numeric(4,2)');
    }
};
