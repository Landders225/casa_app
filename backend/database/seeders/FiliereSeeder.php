<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Les 5 filières CQP + leurs compétences clés, conformes à
 * App_maquette/assets/js/mock-data.js (CASA_CQP).
 *
 * `upsert` sur `filiere` keyed par `code` (unique en base) — même patron que
 * `TypeDocumentSeeder` (Lot 18) : `--force` (`casa:seed-referentiel`) rejoue
 * ce seeder sans dupliquer les 5 lignes ni écraser leur `id` (préservé sur
 * conflit, cf. `docs/POINTS-OUVERTS.md`, incohérence seeders trouvée à la
 * revue pré-prod). `filiere_competence` n'a pas de clé métier propre : purgée
 * puis reconstruite à l'identique pour chaque filière, plutôt qu'un upsert
 * artificiel sur un ordre de compétence.
 */
class FiliereSeeder extends Seeder
{
    public function run(): void
    {
        $filieres = [
            [
                'code' => 'accueil-reception',
                'nom' => 'Accueil-réception',
                'icone' => 'fa-bell-concierge',
                'description' => "Accueillir, renseigner et fidéliser la clientèle d'un établissement hôtelier, de l'arrivée au départ.",
                'competences' => ['Accueil client', 'Réservations', 'Standard téléphonique', 'Facturation'],
            ],
            [
                'code' => 'entretien-hotelier',
                'nom' => "Agent d'entretien hôtelier",
                'icone' => 'fa-broom',
                'description' => 'Assurer la propreté et la remise en état des chambres et des espaces communs selon les standards hôteliers.',
                'competences' => ['Techniques de nettoyage', 'Housekeeping', 'Hygiène & sécurité', 'Gestion du linge'],
            ],
            [
                'code' => 'buanderie',
                'nom' => 'Agent de buanderie',
                'icone' => 'fa-shirt',
                'description' => "Traiter, entretenir et gérer le linge d'un établissement hôtelier ou de restauration.",
                'competences' => ['Lavage professionnel', 'Repassage', 'Tri & traçabilité', 'Maintenance de 1er niveau'],
            ],
            [
                'code' => 'restaurant-bar',
                'nom' => 'Agent de service restaurant-bar',
                'icone' => 'fa-martini-glass-citrus',
                'description' => "Assurer le service en salle et au bar dans le respect des standards de qualité et d'hygiène.",
                'competences' => ['Dressage & service', 'Techniques de bar', 'Relation client', 'Hygiène HACCP'],
            ],
            [
                'code' => 'cuisine',
                'nom' => 'Agent de cuisine',
                'icone' => 'fa-kitchen-set',
                'description' => 'Participer à la préparation et à la production culinaire dans une cuisine professionnelle.',
                'competences' => ['Techniques culinaires de base', 'Hygiène HACCP', "Organisation d'une brigade", 'Gestion des stocks'],
            ],
        ];

        $now = now();

        foreach ($filieres as $f) {
            DB::table('filiere')->upsert([
                'id' => (string) Str::uuid(),
                'code' => $f['code'],
                'nom' => $f['nom'],
                'description' => $f['description'],
                'icone' => $f['icone'],
                'actif' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], uniqueBy: ['code'], update: ['nom', 'description', 'icone', 'updated_at']);

            $filiereId = DB::table('filiere')->where('code', $f['code'])->value('id');

            DB::table('filiere_competence')->where('filiere_id', $filiereId)->delete();
            foreach ($f['competences'] as $ordre => $libelle) {
                DB::table('filiere_competence')->insert([
                    'id' => (string) Str::uuid(),
                    'filiere_id' => $filiereId,
                    'libelle' => $libelle,
                    'ordre' => $ordre,
                ]);
            }
        }
    }
}
