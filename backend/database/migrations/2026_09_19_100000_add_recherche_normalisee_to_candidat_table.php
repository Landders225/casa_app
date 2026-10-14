<?php

use App\Models\Candidat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — Lot recherche candidatures admin (ADR-39). `candidat.recherche_normalisee`
 * = `prenom`+`nom` normalisés (casse, accents, apostrophes, tirets — via
 * `Candidat::normaliserRecherche()`, SOURCE UNIQUE), recalculée à chaque
 * sauvegarde par `Candidat::booted()`.
 *
 * Choix : une colonne PRÉCALCULÉE côté PHP, pas un `unaccent()`/`lower()`
 * SQL appelé en `whereRaw` — `PasDeSqlBrutTest` (Lot 10) interdit toute
 * requête SQL brute sous `app/` ; `where('candidat.recherche_normalisee',
 * 'ilike', ...)` est un `where()` Eloquent ordinaire, aucune exception à
 * whitelister. Effet de bord utile : le calcul se fait UNE fois à l'écriture,
 * pas à chaque lecture.
 *
 * Backfill : délègue à `Candidat::rafraichirRechercheNormalisee()` — Query
 * Builder BRUT (`DB::table('candidat')->update()`), PAS `save()`/le hook
 * `saving()` : un backfill n'est pas une vraie modification de la ligne,
 * `updated_at` reste donc inchangé pour toutes les lignes existantes (preuve
 * dans `CandidatRechercheNormaliseeTest`). `Candidat::normaliserRecherche()`
 * reste l'UNIQUE fonction de normalisation (appelée directement en PHP,
 * jamais dupliquée) — la méthode est testable isolément sans dépendre d'une
 * commande artisan manuelle oubliable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidat', function (Blueprint $table) {
            $table->string('recherche_normalisee', 220)->default('')->after('nom');
        });

        Candidat::rafraichirRechercheNormalisee();
    }

    public function down(): void
    {
        Schema::table('candidat', function (Blueprint $table) {
            $table->dropColumn('recherche_normalisee');
        });
    }
};
