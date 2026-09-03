<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — Lot 7. `utilisateur.cgu_acceptees_le` : horodatage de l'acceptation des
 * conditions d'utilisation à l'inscription (`inscription.html` : case obligatoire).
 *
 * L'acceptation des CGU est un acte juridique : pouvoir prouver QUAND elle a eu
 * lieu a de la valeur en cas de litige (cohérent avec la culture traçabilité du
 * projet — cf. journal_audit append-only, ADR-12). Nullable : les 3 comptes de
 * démonstration seedés (Lot 1/2) et les comptes équipe n'ont pas de parcours
 * d'inscription.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utilisateur', function (Blueprint $table) {
            $table->timestampTz('cgu_acceptees_le')->nullable()->after('derniere_connexion_le');
        });
    }

    public function down(): void
    {
        Schema::table('utilisateur', function (Blueprint $table) {
            $table->dropColumn('cgu_acceptees_le');
        });
    }
};
