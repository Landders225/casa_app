<?php

namespace App\Console\Commands;

use App\Models\Candidature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * OUTIL DE TEST E2E UNIQUEMENT (Lot 19, correctif de revue) — jamais appelée
 * en production, jamais dans `php artisan test`.
 *
 * `DemoDocumentsSeeder` écrit des pièces PLACEHOLDER (texte brut, pas un PDF
 * structuré ni une image réelle — suffisant pour tester le téléchargement,
 * pas le rendu). Cette commande remplace le CONTENU sur disque des pièces
 * `cni` (PDF réel) et `photo` (PNG réel) de la candidature de démonstration
 * `CASA-2026-DOC001`, depuis deux fichiers passés en argument — utilisée par
 * `frontend/e2e/apercu-piece.spec.js` pour vérifier un VRAI rendu PDF/image,
 * sans passer par une chaîne d'échappement shell fragile (`tinker --execute`
 * multi-ligne échouait silencieusement sur certaines invocations Windows).
 *
 *   php artisan casa:e2e-apercu-fixtures /tmp/reel.pdf /tmp/reel.png
 */
class E2eApercuFixtures extends Command
{
    protected $signature = 'casa:e2e-apercu-fixtures {pdf : Chemin absolu d\'un vrai PDF} {png : Chemin absolu d\'un vrai PNG}';

    protected $description = "[E2E UNIQUEMENT] Remplace les pièces cni/photo de CASA-2026-DOC001 par de vrais fichiers";

    public function handle(): int
    {
        abort_if(app()->environment('production'), 403, 'Commande de test, jamais en production.');

        $candidature = Candidature::where('numero_dossier', 'CASA-2026-DOC001')->first();
        if ($candidature === null) {
            $this->error('CASA-2026-DOC001 introuvable — lancez DemoDocumentsSeeder avant cette commande.');

            return self::FAILURE;
        }

        $disque = Storage::disk('documents');

        $cni = $candidature->piecesDossier()->where('type_document_code', 'cni')->first();
        $octetsPdf = file_get_contents($this->argument('pdf'));
        $disque->put($cni->chemin_stockage, $octetsPdf);
        $cni->forceFill(['taille_octets' => strlen($octetsPdf)])->save();

        $photo = $candidature->piecesDossier()->where('type_document_code', 'photo')->first();
        $octetsPng = file_get_contents($this->argument('png'));
        $disque->put($photo->chemin_stockage, $octetsPng);
        $photo->forceFill([
            'taille_octets' => strlen($octetsPng),
            'type_mime' => 'image/png',
            'nom_original' => 'photo.png',
        ])->save();

        $this->info("cni={$cni->taille_octets} o, photo={$photo->taille_octets} o (image/png)");

        return self::SUCCESS;
    }
}
