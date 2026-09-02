<?php

namespace App\Http\Controllers\Api\Candidat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Candidat\DeposerPieceRequest;
use App\Http\Resources\PieceJustificativeResource;
use App\Models\Candidature;
use App\Models\PieceJustificative;
use App\Services\StockagePieces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Pièces du dossier (rattachement='dossier') — 1 par type parmi les 6 du
 * référentiel `type_document`. Le PUT est un UPSERT : dépose OU remplace, ne
 * duplique jamais.
 */
class PieceDossierController extends Controller
{
    public function __construct(private readonly StockagePieces $stockage)
    {
    }

    /**
     * POST /api/candidatures/{candidature}/pieces/{type}
     */
    public function deposer(DeposerPieceRequest $request, Candidature $candidature, string $type): JsonResponse
    {
        $this->authorize('update', $candidature);

        /** @var PieceJustificative|null $existante */
        $existante = $candidature->piecesDossier()
            ->where('rattachement', 'dossier')
            ->where('type_document_code', $type)
            ->first();
        $ancienChemin = $existante?->chemin_stockage;

        $meta = $this->stockage->stocker($request->file('fichier'), $candidature->id);

        try {
            $piece = DB::transaction(function () use ($candidature, $type, $existante, $meta) {
                $attributs = [
                    'nom_original' => $meta['nom_original'],
                    'chemin_stockage' => $meta['chemin_stockage'],
                    'taille_octets' => $meta['taille_octets'],
                    'type_mime' => $meta['type_mime'],
                    'depose_le' => now(),
                ];

                if ($existante !== null) {
                    $existante->update($attributs);

                    return $existante->fresh();
                }

                return $candidature->piecesDossier()->create($attributs + [
                    'rattachement' => 'dossier',
                    'type_document_code' => $type,
                ]);
            });
        } catch (\Throwable $e) {
            // Le nouveau fichier vient d'être écrit mais la ligne a échoué.
            $this->stockage->supprimer($meta['chemin_stockage']);
            throw $e;
        }

        if ($existante !== null && $ancienChemin !== $meta['chemin_stockage']) {
            $this->stockage->supprimer($ancienChemin);
        }

        return (new PieceJustificativeResource($piece))
            ->response()
            ->setStatusCode($existante !== null ? 200 : 201);
    }

    /**
     * DELETE /api/candidatures/{candidature}/pieces/{type}
     */
    public function destroy(Candidature $candidature, string $type): Response
    {
        $this->authorize('update', $candidature);

        /** @var PieceJustificative|null $piece */
        $piece = $candidature->piecesDossier()
            ->where('rattachement', 'dossier')
            ->where('type_document_code', $type)
            ->first();

        abort_if($piece === null, 404, 'Aucune pièce de ce type.');

        $chemin = $piece->chemin_stockage;
        DB::transaction(fn () => $piece->delete());
        $this->stockage->supprimer($chemin);

        return response()->noContent();
    }
}
