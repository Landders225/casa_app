<?php

namespace Database\Seeders;

use App\Models\Campagne;
use App\Models\Candidat;
use App\Models\Candidature;
use App\Models\EvaluationDossier;
use App\Models\Filiere;
use App\Models\Grille;
use App\Models\MembreEquipe;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Contexte de démonstration pour le classement (Lot 5a) — À INVOQUER
 * EXPLICITEMENT (hors DatabaseSeeder) :
 *
 *   php artisan db:seed --class=DemoClassementSeeder
 *
 * Crée quelques candidatures ÉVALUÉES (dossier + entretien figés) dans la
 * campagne ouverte, avec des scores et attributs de départage variés — dont un
 * cas d'égalité stricte de score final et un candidat non éligible.
 */
class DemoClassementSeeder extends Seeder
{
    public function run(): void
    {
        $campagne = Campagne::where('statut', 'ouverte')->firstOrFail();
        $grilleId = Grille::active()->id;
        $filiere = Filiere::where('code', 'cuisine')->firstOrFail();

        $evaluateurUser = User::firstOrCreate(
            ['email' => 'eval-classement@casa-demo.ci'],
            ['mot_de_passe_hash' => 'Demo2026!', 'role' => 'evaluateur', 'actif' => true],
        );
        $membre = MembreEquipe::firstOrCreate(
            ['utilisateur_id' => $evaluateurUser->id],
            ['prenom' => 'Yann', 'nom' => 'Kacou', 'poste' => "Chargé d'évaluation"],
        );

        // [prenom, sexe, dossier, entretien, se02, mo04, domaines, eligibilite]
        $profils = [
            ['Awa', 'F', 55.0, 30.0, 'non', 4, [], 'eligible'],           // 85.0
            ['Koffi', 'H', 60.0, 28.0, 'non', 3, ['hotellerie'], 'eligible'], // 88.0
            ['Mariam', 'F', 50.0, 32.0, 'oui', 5, [], 'eligible'],        // 82.0
            ['Ismael', 'H', 40.0, 20.0, 'non', 3, [], 'eligible'],        // 60.0
            ['Fatou', 'F', 40.0, 20.0, 'non', 3, [], 'eligible'],         // 60.0 — égalité avec Ismael, F -> devant
            ['Sekou', 'H', 58.0, 30.0, 'non', 4, [], 'non_eligible'],     // évalué mais non éligible
        ];

        foreach ($profils as $i => [$prenom, $sexe, $dossier, $entretien, $se02, $mo04, $domaines, $eligibilite]) {
            $user = User::firstOrCreate(
                ['email' => 'classement'.$i.'@casa-demo.ci'],
                ['mot_de_passe_hash' => 'Demo2026!', 'role' => 'candidat', 'actif' => true],
            );
            $candidat = Candidat::firstOrCreate(
                ['utilisateur_id' => $user->id],
                [
                    'prenom' => $prenom, 'nom' => 'Démo', 'sexe' => $sexe,
                    'date_naissance' => '2003-03-03', 'cni' => 'CI'.str_pad((string) (700000000 + $i), 9, '0'),
                    'telephone' => '0700000000', 'ville_residence' => 'Abidjan - Cocody', 'residence_ci' => true,
                ],
            );

            $candidature = Candidature::firstOrCreate(
                ['candidat_id' => $candidat->id, 'campagne_id' => $campagne->id],
                [
                    'filiere_id' => $filiere->id,
                    'numero_dossier' => 'CASA-2026-90'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                    'cqp_confirme' => true,
                ],
            );

            $candidature->reponseFormulaire()->firstOrCreate([]);
            $candidature->reponseFormulaire->forceFill([
                'se02_orphelin' => $se02, 'se06_soutien_menage' => 'non', 'se05_personnes_a_charge' => '0',
                'mo04_note_etoiles' => $mo04,
            ])->save();

            $candidature->experiences()->delete();
            foreach ($domaines as $domaine) {
                $candidature->experiences()->create(['domaine' => $domaine, 'duree_categorie' => '6_12']);
            }

            $candidature->forceFill([
                'evaluateur_id' => $membre->id,
                'statut_interne' => 'evalue',
                'statut_eligibilite_interne' => $eligibilite,
                'dossier_verrouille' => true, 'dossier_verrouille_le' => now(), 'dossier_verrouille_par' => $membre->id,
                'date_soumission' => now()->subDays(20 - $i), // ordres distincts pour le tie-break
                'date_evaluation' => now()->subDays(3),
            ])->saveQuietly();

            EvaluationDossier::updateOrCreate(
                ['candidature_id' => $candidature->id],
                [
                    'grille_id' => $grilleId, 'score_total' => number_format($dossier, 1, '.', ''),
                    'valide' => true, 'valide_le' => now(), 'valide_par' => $membre->id,
                ],
            );

            $candidature->entretien()->firstOrCreate(
                [],
                [
                    'statut' => 'valide', 'date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Le Plateau',
                    'evaluateur_id' => $membre->id, 'presence' => 'present', 'grille_id' => $grilleId,
                    'score_total' => number_format($entretien, 1, '.', ''), 'valide_le' => now(), 'valide_par' => $membre->id,
                ],
            );
        }

        $this->command?->info('6 candidatures évaluées (filière cuisine) prêtes pour le classement.');
    }
}
