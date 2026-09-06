<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `reponse_formulaire` (docs/mld.md §3, ADR-05). Table entière 🔴.
 * 1-1 avec `candidature`. Toutes les réponses brutes du formulaire, persistées
 * intégralement (recalcul serveur + audit ancienne/nouvelle valeur). Reflète
 * 1:1 les champs de App_maquette/assets/js/scoring.js.
 * Aucune colonne diplôme / nationalité ici (ADR-07). `acces_plateau` /
 * `acces_deux_plateaux_vallons` correspondent à DI.04 / DI.05 de la grille.
 * Tous les champs sont nullable : un dossier au statut `brouillon` peut être
 * partiellement rempli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reponse_formulaire', function (Blueprint $table) {
            $table->uuid('candidature_id')->primary();

            // --- Profil scolaire (SC) ---
            $table->string('sc01_scolarise_actuellement', 10)->nullable();
            $table->string('sc02_derniere_classe', 20)->nullable();
            $table->string('sc03_document_justifiant_niveau', 10)->nullable();
            $table->string('sc05_beneficiaire_formation_actuelle', 10)->nullable();
            $table->string('sc06_deja_beneficie_formation', 10)->nullable();   // non noté, pas de CHECK (MLD)
            $table->text('sc07_filiere_suivie')->nullable();
            $table->string('sc08_mene_a_terme', 10)->nullable();               // non noté, pas de CHECK (MLD)
            $table->text('sc09_motif_non_achevement')->nullable();

            // --- Situation socio-économique (SE) ---
            $table->string('se01_vit_avec', 10)->nullable();
            $table->string('se02_orphelin', 10)->nullable();
            $table->string('se03_situation_emploi', 20)->nullable();
            $table->string('se04_source_revenu', 10)->nullable();
            $table->string('se05_personnes_a_charge', 5)->nullable();
            $table->string('se06_soutien_menage', 10)->nullable();

            // --- Langues & informatique (échelle 0..3) ---
            $table->smallInteger('langue_ecrit')->nullable();
            $table->smallInteger('langue_parle')->nullable();
            $table->smallInteger('langue_comprehension')->nullable();
            $table->smallInteger('info_word')->nullable();
            $table->smallInteger('info_excel')->nullable();
            $table->smallInteger('info_internet')->nullable();

            // --- Accès aux sites (DI.04 / DI.05, logique OR — règle 3) ---
            $table->string('acces_plateau', 10)->nullable();
            $table->string('acces_deux_plateaux_vallons', 10)->nullable();

            // --- Motivation (MO.04) ---
            $table->string('mo04_lettre_motivation', 500)->nullable();
            $table->smallInteger('mo04_note_etoiles')->nullable();             // saisie évaluateur (0..5)

            // --- Disponibilité (DI) ---
            $table->string('di01_disponible_lun_ven', 10)->nullable();
            $table->string('di02_contraintes', 10)->nullable();
            $table->string('di03_engagement_complet', 10)->nullable();

            $table->timestampTz('updated_at')->nullable();

            $table->foreign('candidature_id')->references('id')->on('candidature');
        });

        // CHECK d'énumération — un NULL passe (sémantique SQL standard : CHECK
        // n'échoue que sur FALSE), cohérent avec le remplissage progressif.
        $enums = [
            'sc01_scolarise_actuellement' => ['oui', 'non'],
            'sc02_derniere_classe' => ['avant_3e', 'cap', '3e', 'seconde', '1ere', 'terminale', 'bt_bep'],
            'sc03_document_justifiant_niveau' => ['oui', 'non'],
            'sc05_beneficiaire_formation_actuelle' => ['oui', 'non'],
            'se01_vit_avec' => ['pere', 'mere', 'les_deux', 'aucun'],
            'se02_orphelin' => ['oui', 'non'],
            'se03_situation_emploi' => ['sans_emploi', 'stage', 'interim', 'temps_partiel', 'temps_plein'],
            'se04_source_revenu' => ['parent', 'conjoint', 'agr', 'aucune'],
            'se05_personnes_a_charge' => ['0', '1-2', '3+'],
            'se06_soutien_menage' => ['oui', 'non'],
            'acces_plateau' => ['oui', 'non'],
            'acces_deux_plateaux_vallons' => ['oui', 'non'],
            'di01_disponible_lun_ven' => ['oui', 'non'],
            'di02_contraintes' => ['aucune', 'gerable', 'bloquante'],
            'di03_engagement_complet' => ['oui', 'non'],
        ];
        foreach ($enums as $col => $vals) {
            $list = implode(', ', array_map(fn ($v) => "'".$v."'", $vals));
            DB::statement("ALTER TABLE reponse_formulaire ADD CONSTRAINT reponse_formulaire_{$col}_check CHECK ({$col} IN ({$list}))");
        }

        foreach (['langue_ecrit', 'langue_parle', 'langue_comprehension', 'info_word', 'info_excel', 'info_internet'] as $col) {
            DB::statement("ALTER TABLE reponse_formulaire ADD CONSTRAINT reponse_formulaire_{$col}_check CHECK ({$col} BETWEEN 0 AND 3)");
        }
        DB::statement('ALTER TABLE reponse_formulaire ADD CONSTRAINT reponse_formulaire_mo04_note_etoiles_check CHECK (mo04_note_etoiles BETWEEN 0 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reponse_formulaire');
    }
};
