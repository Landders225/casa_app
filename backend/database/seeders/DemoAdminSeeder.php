<?php

namespace Database\Seeders;

use App\Models\Campagne;
use App\Models\Candidat;
use App\Models\Candidature;
use App\Models\Filiere;
use App\Models\MembreEquipe;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Contexte de démonstration pour l'espace administration (Lot 6a) — À INVOQUER
 * EXPLICITEMENT (hors DatabaseSeeder) :
 *
 *   php artisan db:seed --class=DemoAdminSeeder
 *
 * Crée un évaluateur et 3 candidatures au statut `soumis` (éligibles), prêtes à
 * être affectées via `POST /api/admin/affectations`.
 */
class DemoAdminSeeder extends Seeder
{
    public function run(): void
    {
        $campagne = Campagne::where('statut', 'ouverte')->firstOrFail();
        $filiere = Filiere::where('code', 'cuisine')->firstOrFail();

        $evaluateurUser = User::firstOrCreate(
            ['email' => 'eval-admin@casa-demo.ci'],
            ['mot_de_passe_hash' => 'Demo2026!', 'role' => 'evaluateur', 'actif' => true],
        );
        MembreEquipe::firstOrCreate(
            ['utilisateur_id' => $evaluateurUser->id],
            ['prenom' => 'Nadège', 'nom' => 'Bamba', 'poste' => "Chargée d'évaluation"],
        );

        foreach (['Aïcha', 'Bakary', 'Célestine'] as $i => $prenom) {
            $user = User::firstOrCreate(
                ['email' => 'soumis'.$i.'@casa-demo.ci'],
                ['mot_de_passe_hash' => 'Demo2026!', 'role' => 'candidat', 'actif' => true],
            );
            $candidat = Candidat::firstOrCreate(
                ['utilisateur_id' => $user->id],
                [
                    'prenom' => $prenom, 'nom' => 'Démo', 'sexe' => 'F',
                    'date_naissance' => '2003-06-15', 'cni' => 'CI'.str_pad((string) (800000000 + $i), 9, '0'),
                    'telephone' => '0700000000', 'ville_residence' => 'Abidjan - Yopougon', 'residence_ci' => true,
                ],
            );

            $candidature = Candidature::firstOrCreate(
                ['candidat_id' => $candidat->id, 'campagne_id' => $campagne->id],
                [
                    'filiere_id' => $filiere->id,
                    'numero_dossier' => 'CASA-2026-80'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                    'cqp_confirme' => true,
                ],
            );

            $candidature->reponseFormulaire()->firstOrCreate([]);
            $candidature->reponseFormulaire->forceFill([
                'sc01_scolarise_actuellement' => 'non', 'sc02_derniere_classe' => 'terminale',
                'sc03_document_justifiant_niveau' => 'oui', 'sc05_beneficiaire_formation_actuelle' => 'non',
                'se03_situation_emploi' => 'sans_emploi',
                'langue_ecrit' => 3, 'langue_parle' => 2, 'langue_comprehension' => 3,
                'acces_plateau' => 'oui', 'acces_deux_plateaux_vallons' => 'non',
                'di01_disponible_lun_ven' => 'oui', 'di03_engagement_complet' => 'oui',
            ])->save();

            $candidature->forceFill([
                'statut_interne' => 'soumis',
                'statut_eligibilite_interne' => 'eligible',
                'date_soumission' => now()->subDays(5 - $i),
            ])->saveQuietly();
        }

        $this->command?->info('3 candidatures « soumis » (filière cuisine) prêtes à affecter.');
    }
}
