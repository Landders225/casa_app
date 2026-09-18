<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — Lot 18. `candidat.numero_cmu` : numéro de Couverture Maladie
 * Universelle, DÉCLARATIF (pas de calcul, pas de vérification serveur), au
 * même niveau que `cni`/`telephone`/`ville_residence` — PAS sur
 * `reponse_formulaire` (réservée au reflet de `scoring.js`, ADR-05).
 *
 * Nullable : un dossier créé AVANT ce lot n'en a pas — `ValidateurCompletude`
 * ne revalide jamais un dossier déjà soumis (seul point d'appel :
 * `SoumissionController`, avant tout calcul de complétude sur une
 * candidature `estBrouillon() === false`), donc aucune candidature existante
 * ne devient rétroactivement invalide. Toujours éditable (comme `telephone`),
 * jamais verrouillé post-soumission (contrairement à `cni` — ce n'est pas un
 * fait d'identité vérifié sur pièce par l'évaluateur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidat', function (Blueprint $table) {
            $table->string('numero_cmu', 50)->nullable()->after('cni');
        });
    }

    public function down(): void
    {
        Schema::table('candidat', function (Blueprint $table) {
            $table->dropColumn('numero_cmu');
        });
    }
};
