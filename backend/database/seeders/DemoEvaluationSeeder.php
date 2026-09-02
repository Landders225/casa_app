<?php

namespace Database\Seeders;

use App\Models\Candidat;
use App\Models\Candidature;
use App\Models\Filiere;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Contexte de démonstration pour l'espace évaluateur (Lot 4a) — À INVOQUER
 * EXPLICITEMENT (hors DatabaseSeeder) :
 *
 *   php artisan db:seed --class=DemoEvaluationSeeder
 *
 * Affecte une candidature soumise du candidat de démo à l'évaluateur de démo,
 * au statut `en_instruction`. L'affectation réelle (par un admin) fera l'objet
 * d'un lot ultérieur — cf. docs/ADR.md ADR-13 (dépendance signalée).
 *
 * ⚠️ Écrit un fichier sur le disque privé `documents` : à lancer avec
 * l'utilisateur www-data pour que PHP-FPM puisse ensuite le lire —
 *   docker compose exec -u www-data backend php artisan db:seed --class=DemoEvaluationSeeder
 */
class DemoEvaluationSeeder extends Seeder
{
    public function run(): void
    {
        $candidatUser = User::where('email', 'candidat@casa-demo.ci')->firstOrFail();
        $evaluateurUser = User::where('email', 'evaluateur@casa-demo.ci')->firstOrFail();

        $candidat = Candidat::where('utilisateur_id', $candidatUser->id)->firstOrFail();
        $membreEquipe = $evaluateurUser->membreEquipe;

        $campagne = \App\Models\Campagne::where('statut', 'ouverte')->firstOrFail();
        $filiere = Filiere::where('code', 'accueil-reception')->firstOrFail();

        $candidature = Candidature::firstOrCreate(
            ['candidat_id' => $candidat->id, 'campagne_id' => $campagne->id],
            [
                'filiere_id' => $filiere->id,
                'numero_dossier' => 'CASA-2026-'.str_pad((string) (Candidature::count() + 1), 6, '0', STR_PAD_LEFT),
                'cqp_confirme' => true,
            ],
        );
        $candidature->reponseFormulaire()->firstOrCreate([]);

        $candidature->forceFill([
            'evaluateur_id' => $membreEquipe->id,
            'statut_interne' => 'en_instruction',
            'statut_eligibilite_interne' => 'eligible',
            'date_soumission' => now()->subDays(2),
        ])->saveQuietly();

        // Un justificatif de dossier (avec un vrai fichier sur le disque privé)
        // pour tester le téléchargement évaluateur.
        $piece = $candidature->piecesDossier()->firstOrCreate(
            ['type_document_code' => 'diplome'],
            [
                'rattachement' => 'dossier',
                'nom_original' => 'diplome_bac.pdf',
                'chemin_stockage' => $candidature->id.'/'.Str::uuid().'.pdf',
                'taille_octets' => 20,
                'type_mime' => 'application/pdf',
                'depose_le' => now()->subDays(3),
            ],
        );
        Storage::disk('documents')->put($piece->chemin_stockage, "%PDF-1.4\n%%EOF\n");

        $this->command?->info("Candidature {$candidature->numero_dossier} affectée à evaluateur@casa-demo.ci (en_instruction).");
    }
}
