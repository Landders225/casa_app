<?php

namespace App\Http\Controllers\Api\Evaluateur;

use App\Http\Controllers\Controller;
use App\Models\PieceJustificative;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PieceEvaluateurController extends Controller
{
    /**
     * GET /api/evaluateur/pieces/{piece}/download — streaming d'une pièce d'un
     * dossier accessible à l'évaluateur (affecté) ou à un admin. 404 sinon.
     *
     * Même durcissement qu'au Lot 3b : streaming depuis le disque privé,
     * toujours `attachment` + `X-Content-Type-Options: nosniff`.
     */
    public function download(PieceJustificative $piece): StreamedResponse
    {
        $this->authorize('downloadCommeEvaluateur', $piece);

        $disque = Storage::disk('documents');
        abort_unless($disque->exists($piece->chemin_stockage), 404, 'Fichier introuvable.');

        return $disque->download($piece->chemin_stockage, $piece->nom_original, [
            'Content-Type' => $piece->type_mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
