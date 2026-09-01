<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Les 5 filières CQP + leurs compétences clés, conformes à
 * App_maquette/assets/js/mock-data.js (CASA_CQP).
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
            $id = (string) Str::uuid();
            DB::table('filiere')->insert([
                'id' => $id,
                'code' => $f['code'],
                'nom' => $f['nom'],
                'description' => $f['description'],
                'icone' => $f['icone'],
                'actif' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($f['competences'] as $ordre => $libelle) {
                DB::table('filiere_competence')->insert([
                    'id' => (string) Str::uuid(),
                    'filiere_id' => $id,
                    'libelle' => $libelle,
                    'ordre' => $ordre,
                ]);
            }
        }
    }
}
