<?php

namespace Tests\Feature\Candidat;

use App\Models\Candidat;
use App\Models\Candidature;
use App\Models\PieceJustificative;
use App\Models\User;
use Database\Seeders\CampagneSeeder;
use Database\Seeders\FiliereSeeder;
use Database\Seeders\TypeDocumentSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Contexte partagé des tests candidature Lot 3a/3b : référentiels seedés
 * (types de documents, filières, campagne « Cohorte 1 » ouverte), fabrique de
 * comptes candidat, et fichiers de test à VRAI contenu.
 *
 * ⚠️ Les fichiers passent par un `Illuminate\Http\UploadedFile` RÉEL (pas
 * `UploadedFile::fake()`) : le fake dérive son MIME de l'extension du nom, ce
 * qui masquerait la validation `mimetypes` par contenu (finfo).
 */
trait CreeContexteCandidature
{
    /** @var list<string> */
    private array $fichiersTempo = [];

    protected function tearDown(): void
    {
        foreach ($this->fichiersTempo as $chemin) {
            @unlink($chemin);
        }
        $this->fichiersTempo = [];

        parent::tearDown();
    }

    protected function seedReferentiels(): void
    {
        $this->seed([TypeDocumentSeeder::class, FiliereSeeder::class, CampagneSeeder::class]);
    }

    /**
     * Compte candidat + profil `candidat` prêt à candidater.
     *
     * @param  array<string, mixed>  $profil  écrase des champs de `candidat` (ex. `sexe`)
     */
    protected function creerCandidat(?string $email = null, array $profil = []): User
    {
        $user = User::factory()->create([
            'role' => 'candidat',
            'email' => $email ?? fake()->unique()->safeEmail(),
        ]);

        Candidat::create(array_merge([
            'utilisateur_id' => $user->id,
            'prenom' => 'Test',
            'nom' => 'Candidat',
            'sexe' => 'F',
            'date_naissance' => '2004-01-01',
            'cni' => 'CI'.fake()->numerify('#########'),
            'telephone' => '0700000000',
            'ville_residence' => 'Abidjan - Cocody',
            'residence_ci' => true,
        ], $profil));

        return $user->fresh();
    }

    protected function idFiliere(string $code): string
    {
        return \App\Models\Filiere::where('code', $code)->value('id');
    }

    // --- Fichiers de test : vrai UploadedFile, vrais magic bytes ---

    private const PNG_1x1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const JPG_1x1 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigD/2Q==';

    protected function fichierReel(string $nom, string $contenu, string $mimeDeclare = 'application/octet-stream'): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'casa_piece_');
        file_put_contents($chemin, $contenu);
        $this->fichiersTempo[] = $chemin;

        // 5e argument $test=true : contourne is_uploaded_file() ; getMimeType()
        // reste basé sur le CONTENU (Symfony finfo), pas sur $mimeDeclare.
        return new UploadedFile($chemin, $nom, $mimeDeclare, null, true);
    }

    protected function contenuPdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
    }

    protected function fichierPdf(string $nom = 'document.pdf'): UploadedFile
    {
        return $this->fichierReel($nom, $this->contenuPdf(), 'application/pdf');
    }

    protected function fichierPng(string $nom = 'photo.png'): UploadedFile
    {
        return $this->fichierReel($nom, base64_decode(self::PNG_1x1), 'image/png');
    }

    protected function fichierJpeg(string $nom = 'photo.jpg'): UploadedFile
    {
        return $this->fichierReel($nom, base64_decode(self::JPG_1x1), 'image/jpeg');
    }

    // --- Candidature prête à soumettre (remplissage direct en base) ---

    /**
     * Jeu de réponses valide et ÉLIGIBLE (aucun critère de scoring.js déclenché).
     *
     * @return array<string, mixed>
     */
    protected function reponsesEligibles(): array
    {
        return [
            'sc01_scolarise_actuellement' => 'non',
            'sc02_derniere_classe' => 'terminale',
            'sc03_document_justifiant_niveau' => 'oui',
            'sc05_beneficiaire_formation_actuelle' => 'non',
            'sc06_deja_beneficie_formation' => 'non',
            'se02_orphelin' => 'non',
            'se03_situation_emploi' => 'sans_emploi',
            'se04_source_revenu' => 'aucune',
            'se06_soutien_menage' => 'non',
            'langue_ecrit' => 3, 'langue_parle' => 2, 'langue_comprehension' => 3,
            'info_word' => 2, 'info_excel' => 1, 'info_internet' => 2,
            'acces_plateau' => 'oui', 'acces_deux_plateaux_vallons' => 'non',
            'mo04_lettre_motivation' => 'Je souhaite intégrer cette formation certifiante pour construire une carrière stable dans l’hôtellerie.',
            'di01_disponible_lun_ven' => 'oui',
            'di02_contraintes' => 'aucune',
            'di03_engagement_complet' => 'oui',
        ];
    }

    /**
     * Rend la candidature complète (donc soumissible). `$reponses` écrase des
     * champs pour tester (in)complétude / (non-)éligibilité.
     *
     * @param  array<string, mixed>  $reponses
     */
    protected function rendreCandidatureComplete(Candidature $candidature, array $reponses = []): Candidature
    {
        $candidature->reponseFormulaire->fill(array_merge($this->reponsesEligibles(), $reponses))->save();
        $candidature->forceFill(['cqp_confirme' => true])->save();

        foreach (['cni', 'residence', 'diplome', 'cv', 'lettre', 'photo'] as $type) {
            PieceJustificative::create([
                'candidature_id' => $candidature->id,
                'type_document_code' => $type,
                'rattachement' => 'dossier',
                'nom_original' => "{$type}.pdf",
                'chemin_stockage' => $candidature->id.'/'.Str::uuid().'.pdf',
                'taille_octets' => 1000,
                'type_mime' => 'application/pdf',
                'depose_le' => now(),
            ]);
        }

        return $candidature->fresh();
    }
}
