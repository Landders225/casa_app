<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Les 3 comptes de démonstration, conformes à App_maquette/assets/js/app.js
 * (CASA_DEMO_ACCOUNTS) : identifiants candidat@ / evaluateur@ / admin@casa-demo.ci,
 * mot de passe "Demo2026!".
 *
 * - Le rôle `admin` de CASA_DEMO_ACCOUNTS est mappé sur `administrateur`
 *   (valeur du CHECK de `utilisateur.role`, cf. ADR-10).
 * - L'identité des profils (candidat / membre_equipe) reprend les personas de
 *   mock-data.js : Aya Konan (demo-candidat), Solange N'Dri (eval-1),
 *   Prisca Yéo (admin-1).
 * - Aucune candidature / réponse / évaluation n'est seedée (hors périmètre du
 *   Lot 1 : schéma + référentiel uniquement).
 */
class ComptesDemoSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $hash = Hash::make('Demo2026!');

        // --- Candidat ---
        $uCandidat = (string) Str::uuid();
        DB::table('utilisateur')->insert([
            'id' => $uCandidat,
            'email' => 'candidat@casa-demo.ci',
            'mot_de_passe_hash' => $hash,
            'role' => 'candidat',
            'actif' => true,
            'cree_le' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('candidat')->insert([
            'id' => (string) Str::uuid(),
            'utilisateur_id' => $uCandidat,
            'prenom' => 'Aya',
            'nom' => 'Konan',
            'sexe' => 'F',
            'date_naissance' => '2003-04-12',
            'cni' => 'CI102030405',
            'telephone' => '0701020304',
            'ville_residence' => 'Abidjan - Cocody',
            'residence_ci' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // --- Évaluateur ---
        $uEval = (string) Str::uuid();
        DB::table('utilisateur')->insert([
            'id' => $uEval,
            'email' => 'evaluateur@casa-demo.ci',
            'mot_de_passe_hash' => $hash,
            'role' => 'evaluateur',
            'actif' => true,
            'cree_le' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('membre_equipe')->insert([
            'id' => (string) Str::uuid(),
            'utilisateur_id' => $uEval,
            'prenom' => 'Solange',
            'nom' => "N'Dri",
            'poste' => "Chargée d'évaluation",
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // --- Administrateur ---
        $uAdmin = (string) Str::uuid();
        DB::table('utilisateur')->insert([
            'id' => $uAdmin,
            'email' => 'admin@casa-demo.ci',
            'mot_de_passe_hash' => $hash,
            'role' => 'administrateur',
            'actif' => true,
            'cree_le' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('membre_equipe')->insert([
            'id' => (string) Str::uuid(),
            'utilisateur_id' => $uAdmin,
            'prenom' => 'Prisca',
            'nom' => 'Yéo',
            'poste' => 'Coordinatrice projet CASA',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
