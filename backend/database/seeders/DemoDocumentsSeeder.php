<?php

namespace Database\Seeders;

use App\Models\Campagne;
use App\Models\Candidat;
use App\Models\Candidature;
use App\Models\Filiere;
use App\Models\PieceJustificative;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Contexte de démonstration pour l'écran Documents (Lot 14) — À INVOQUER
 * EXPLICITEMENT (hors DatabaseSeeder) :
 *
 *   php artisan db:seed --class=DemoDocumentsSeeder
 *
 * Candidature SOUMISE avec les 6 pièces du dossier + 1 expérience justifiée —
 * de VRAIS fichiers écrits sur le disque privé `documents` (pas de simulation :
 * l'E2E doit pouvoir réellement télécharger et vérifier le contenu reçu).
 */
class DemoDocumentsSeeder extends Seeder
{
    public function run(): void
    {
        $campagne = Campagne::where('statut', 'ouverte')->firstOrFail();
        $filiere = Filiere::where('code', 'cuisine')->firstOrFail();

        $user = User::firstOrCreate(
            ['email' => 'documents@casa-demo.ci'],
            ['mot_de_passe_hash' => 'Demo2026!', 'role' => 'candidat', 'actif' => true],
        );
        $candidat = Candidat::firstOrCreate(
            ['utilisateur_id' => $user->id],
            [
                'prenom' => 'Yasmine', 'nom' => 'Démo', 'sexe' => 'F',
                'date_naissance' => '2002-02-02', 'cni' => 'CI999888777',
                'telephone' => '0700000099', 'ville_residence' => 'Abidjan - Cocody', 'residence_ci' => true,
            ],
        );

        $candidature = Candidature::firstOrCreate(
            ['candidat_id' => $candidat->id, 'campagne_id' => $campagne->id],
            ['filiere_id' => $filiere->id, 'numero_dossier' => 'CASA-2026-DOC001', 'cqp_confirme' => true],
        );
        $candidature->forceFill([
            'statut_interne' => 'soumis',
            'statut_eligibilite_interne' => 'eligible',
            'date_soumission' => now(),
        ])->saveQuietly();

        $disque = Storage::disk('documents');
        $candidature->piecesDossier()->delete();
        foreach (['cni', 'residence', 'diplome', 'cv', 'lettre', 'photo'] as $type) {
            $chemin = $candidature->id.'/'.Str::uuid().'.pdf';
            $disque->put($chemin, "%PDF-1.4\nContenu de démonstration — {$type}.\n%%EOF");
            PieceJustificative::create([
                'candidature_id' => $candidature->id,
                'type_document_code' => $type,
                'rattachement' => 'dossier',
                'nom_original' => "{$type}.pdf",
                'chemin_stockage' => $chemin,
                'taille_octets' => $disque->size($chemin),
                'type_mime' => 'application/pdf',
                'depose_le' => now(),
            ]);
        }

        $candidature->experiences()->delete();
        // `candidature_id` n'est PAS mass-assignable (fillable = domaine/durée
        // seulement) : passer par la relation, comme DemoClassementSeeder.
        $experience = $candidature->experiences()->create([
            'domaine' => 'hotellerie',
            'duree_categorie' => '6_12',
        ]);
        $cheminExp = $candidature->id.'/'.Str::uuid().'.pdf';
        $disque->put($cheminExp, "%PDF-1.4\nContenu de démonstration — justificatif d'expérience.\n%%EOF");
        $pieceExp = PieceJustificative::create([
            'rattachement' => 'experience',
            'nom_original' => 'attestation-hotellerie.pdf',
            'chemin_stockage' => $cheminExp,
            'taille_octets' => $disque->size($cheminExp),
            'type_mime' => 'application/pdf',
            'depose_le' => now(),
        ]);
        $experience->forceFill(['piece_justificative_id' => $pieceExp->id])->save();

        $this->command?->info("Candidature {$candidature->numero_dossier} soumise, 6 pièces + 1 justificatif d'expérience, prête pour /candidat/documents.");
    }
}
