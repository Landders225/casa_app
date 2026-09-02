<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CASA — Lot 4c (D-4c-3). `entretien.lieu` n'avait pas de contrainte
 * d'énumération (le MLD la cite en commentaire seulement). On l'ajoute pour
 * rester cohérent avec le reste du schéma : les deux sites de formation de la
 * maquette (`entretien.html`).
 *
 * ⚠️ Divergence de vocabulaire signalée : ces libellés (`Le Plateau`,
 * `2 Plateaux Vallons`) désignent les deux mêmes sites physiques que les items
 * d'éligibilité DI.04 / DI.05 (`reponse_formulaire.acces_plateau` /
 * `acces_deux_plateaux_vallons`, formulés « au Plateau » / « aux 2 Plateaux
 * Vallons »). Types différents (énum texte ici, booléens là), aucun couplage
 * technique — simple différence de forme du libellé.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE entretien ADD CONSTRAINT entretien_lieu_check CHECK (lieu IN ('Le Plateau', '2 Plateaux Vallons'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE entretien DROP CONSTRAINT entretien_lieu_check');
    }
};
