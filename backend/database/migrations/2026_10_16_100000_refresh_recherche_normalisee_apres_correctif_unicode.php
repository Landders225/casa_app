<?php

use App\Models\Candidat;
use Illuminate\Database\Migrations\Migration;

/**
 * CORRECTIF PROD (lot recherche-admin, ADR-39) — constat réel : 1 candidat
 * sur 527 avait `recherche_normalisee = ''` après le backfill initial
 * (migration `2026_09_19_100000_add_recherche_normalisee_to_candidat_table`).
 * Son prénom et son nom sont écrits ENTIÈREMENT en lettres Unicode
 * « mathématiques » (blocs Mathematical Alphanumeric Symbols,
 * U+1D400–U+1D7FF, + U+210E) : 0 lettre ASCII. `Str::ascii()` seul ne
 * connaît que le bloc Latin et les supprimait silencieusement.
 *
 * `Candidat::normaliserRecherche()` applique désormais une normalisation
 * Unicode NFKC AVANT la translittération ASCII (réduit ces variantes de
 * PRÉSENTATION à leur forme canonique — 𝑎𝑏𝑐 → abc) — mais ce changement
 * de fonction ne touche, par construction, QUE les lignes sauvegardées
 * APRÈS lui (le hook `Candidat::booted()`). Cette migration re-déclenche
 * le backfill (`Candidat::rafraichirRechercheNormalisee()` — SOURCE
 * UNIQUE, déjà utilisée par la première migration, pas une seconde
 * définition) pour que les lignes déjà en base bénéficient elles aussi du
 * correctif. Même mécanisme que la première fois : Query Builder BRUT
 * (`DB::table()->update()`), pas `save()` — `updated_at` inchangé, aucun
 * événement de modèle.
 *
 * `down()` : aucune action. Il n'existe pas d'« ancienne » valeur à
 * restaurer de façon fiable (la normalisation d'avant ce correctif n'est
 * plus calculable après coup sans rejouer l'ancien code) — exactement le
 * même choix que la migration précédente pour son `down()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Candidat::rafraichirRechercheNormalisee();
    }

    public function down(): void
    {
        // Volontairement vide — voir le commentaire de classe.
    }
};
