<?php

namespace App\Http\Controllers\Api\Evaluateur;

use App\Domain\Audit\JournalisationConsultationPiece;
use App\Http\Controllers\Controller;
use App\Models\PieceJustificative;
use Illuminate\Http\Request;
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
     *
     * Journalisée au même titre que l'aperçu (Lot 19) : sans ça, le
     * téléchargement direct contournerait silencieusement la traçabilité des
     * consultations.
     */
    public function download(Request $request, PieceJustificative $piece): StreamedResponse
    {
        $this->authorize('downloadCommeEvaluateur', $piece);
        JournalisationConsultationPiece::enregistrerSiNouvelle($request->user(), $piece);

        $disque = Storage::disk('documents');
        abort_unless($disque->exists($piece->chemin_stockage), 404, 'Fichier introuvable.');

        return $disque->download($piece->chemin_stockage, $piece->nom_original, [
            'Content-Type' => $piece->type_mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * GET /api/evaluateur/pieces/{piece}/apercu — EXACTEMENT la même
     * autorisation que `download` (Lot 19), affichage en ligne plutôt
     * qu'enregistrement :
     *  - `inline` (pas `attachment`) : la pièce reste dans l'écran, jamais sur
     *    le disque de l'utilisateur ;
     *  - `no-store` : jamais mis en cache (disque ou intermédiaire) ;
     *  - `Content-Security-Policy: sandbox; default-src 'none'` sur CETTE
     *    réponse précisément (en plus de la CSP globale posée par nginx) :
     *    si cette URL était un jour ouverte directement dans un onglet (hors
     *    du flux `fetch` + aperçu applicatif), le navigateur la rend dans un
     *    contexte sans origine, sans script, sans plugin — même si le contenu
     *    n'était pas le PDF/image attendu.
     */
    public function apercu(Request $request, PieceJustificative $piece): StreamedResponse
    {
        $this->authorize('downloadCommeEvaluateur', $piece);
        JournalisationConsultationPiece::enregistrerSiNouvelle($request->user(), $piece);

        $disque = Storage::disk('documents');
        abort_unless($disque->exists($piece->chemin_stockage), 404, 'Fichier introuvable.');

        return $disque->response($piece->chemin_stockage, $piece->nom_original, [
            'Content-Type' => $piece->type_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
        ]);
    }
}
