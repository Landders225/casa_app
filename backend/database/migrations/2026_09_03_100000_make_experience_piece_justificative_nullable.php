<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CASA — Lot 3a. Le brouillon de candidature permet d'ajouter une expérience
 * professionnelle AVANT d'y attacher son justificatif (upload = Lot 3b). Or
 * `experience_professionnelle.piece_justificative_id` était NOT NULL (MLD Lot 0).
 *
 * On la rend nullable. La règle « 1 expérience = 1 justificatif » (MCD) devient
 * une VALIDATION À LA SOUMISSION (Lot 3c), pas une contrainte de colonne — même
 * logique que le formulaire de la maquette (on ajoute l'expérience, puis on
 * dépose le document). L'index UNIQUE est conservé (une pièce ne sert qu'à une
 * seule expérience) et tolère les NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE experience_professionnelle ALTER COLUMN piece_justificative_id DROP NOT NULL');
    }

    public function down(): void
    {
        // Ne peut réussir que si aucune ligne n'a de piece_justificative_id NULL.
        DB::statement('ALTER TABLE experience_professionnelle ALTER COLUMN piece_justificative_id SET NOT NULL');
    }
};
