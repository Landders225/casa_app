<?php

namespace App\Http\Controllers\Api\Candidat;

use App\Http\Controllers\Controller;
use App\Http\Resources\PieceJustificativeResource;
use App\Models\Candidature;
use App\Models\PieceJustificative;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PieceController extends Controller
{
    /**
     * GET /api/candidatures/{candidature}/pieces — liste des pièces (métadonnées).
     */
    public function index(Candidature $candidature): JsonResponse
    {
        $this->authorize('view', $candidature);

        $candidature->load(['piecesDossier', 'experiences.pieceJustificative']);

        return response()->json([
            'data' => [
                'dossier' => PieceJustificativeResource::collection($candidature->piecesDossier),
                'experiences' => $candidature->experiences->map(fn ($experience) => [
                    'experience_id' => $experience->id,
                    'justificatif' => $experience->pieceJustificative
                        ? new PieceJustificativeResource($experience->pieceJustificative)
                        : null,
                ])->all(),
            ],
        ]);
    }

    /**
     * GET /api/pieces/{piece}/download — streaming depuis le disque privé.
     *
     * Toujours `attachment` + `X-Content-Type-Options: nosniff` : un PDF/image
     * validé par magic bytes peut porter une charge active ; on empêche tout
     * rendu/exécution dans le navigateur.
     */
    public function download(PieceJustificative $piece): StreamedResponse
    {
        $this->authorize('download', $piece);

        $disque = Storage::disk('documents');
        abort_unless($disque->exists($piece->chemin_stockage), 404, 'Fichier introuvable.');

        return $disque->download($piece->chemin_stockage, $piece->nom_original, [
            'Content-Type' => $piece->type_mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
