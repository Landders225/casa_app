<?php

namespace App\Services;

use App\Domain\Piece\ContraintesFichier;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Écriture / suppression des fichiers de pièces sur le disque privé `documents`
 * (hors webroot, ADR-11).
 *
 * - Le nom de stockage est 100 % serveur : `{candidature_id}/{uuid}.{ext}`,
 *   `ext` dérivée du MIME détecté par contenu — jamais du nom ni de l'extension
 *   cliente (aucun path traversal, aucune collision).
 * - `nom_original` est conservé assaini, pour l'affichage uniquement.
 */
class StockagePieces
{
    private function disque(): Filesystem
    {
        return Storage::disk('documents');
    }

    /**
     * Stocke le fichier et renvoie les métadonnées à persister.
     *
     * @return array{chemin_stockage: string, nom_original: string, type_mime: string, taille_octets: int}
     */
    public function stocker(UploadedFile $fichier, string $candidatureId): array
    {
        // MIME détecté par le CONTENU (finfo) — déjà validé par DeposerPieceRequest.
        $mime = $fichier->getMimeType();
        $ext = ContraintesFichier::EXTENSION_PAR_MIME[$mime]
            ?? throw new RuntimeException("MIME non pris en charge : {$mime}");

        $dossier = $candidatureId;
        $nomServeur = Str::uuid()->toString().'.'.$ext;

        $chemin = $this->disque()->putFileAs($dossier, $fichier, $nomServeur);
        if ($chemin === false) {
            throw new RuntimeException('Échec de l\'écriture du fichier sur le disque privé.');
        }

        return [
            'chemin_stockage' => $chemin,
            'nom_original' => $this->assainirNom($fichier->getClientOriginalName()),
            'type_mime' => $mime,
            'taille_octets' => $fichier->getSize(),
        ];
    }

    public function supprimer(?string $chemin): void
    {
        if ($chemin === null || $chemin === '') {
            return;
        }

        if ($this->disque()->exists($chemin)) {
            $this->disque()->delete($chemin);
        }
    }

    /**
     * basename + retrait des caractères de contrôle + longueur bornée.
     * Uniquement pour l'affichage — jamais utilisé pour construire un chemin.
     */
    private function assainirNom(?string $nom): string
    {
        $nom = (string) $nom;
        $base = basename(str_replace('\\', '/', $nom));
        $base = preg_replace('/[\x00-\x1F\x7F]/u', '', $base) ?? '';
        $base = trim($base);

        return $base === '' ? 'fichier' : Str::limit($base, 200, '');
    }
}
