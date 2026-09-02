<?php

namespace App\Http\Controllers\Api\Candidat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Candidat\DeposerPieceRequest;
use App\Http\Resources\PieceJustificativeResource;
use App\Models\Candidature;
use App\Models\ExperienceProfessionnelle;
use App\Models\PieceJustificative;
use App\Services\StockagePieces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Justificatif d'une expérience (rattachement='experience', candidature_id +
 * type_document_code NULL — cf. CHECK d'exclusivité). 0 ou 1 par expérience,
 * UPSERT au PUT. `{experience}` est scellée sur `{candidature}` (scopeBindings).
 */
class JustificatifExperienceController extends Controller
{
    public function __construct(private readonly StockagePieces $stockage)
    {
    }

    /**
     * POST /api/candidatures/{candidature}/experiences/{experience}/justificatif
     */
    public function deposer(
        DeposerPieceRequest $request,
        Candidature $candidature,
        ExperienceProfessionnelle $experience,
    ): JsonResponse {
        $this->authorize('update', $candidature);

        $ancienne = $experience->pieceJustificative;
        $ancienChemin = $ancienne?->chemin_stockage;

        $meta = $this->stockage->stocker($request->file('fichier'), $candidature->id);

        try {
            $piece = DB::transaction(function () use ($experience, $ancienne, $meta) {
                $attributs = [
                    'nom_original' => $meta['nom_original'],
                    'chemin_stockage' => $meta['chemin_stockage'],
                    'taille_octets' => $meta['taille_octets'],
                    'type_mime' => $meta['type_mime'],
                    'depose_le' => now(),
                ];

                if ($ancienne !== null) {
                    $ancienne->update($attributs);

                    return $ancienne->fresh();
                }

                $nouvelle = PieceJustificative::create($attributs + [
                    'rattachement' => 'experience',
                    'candidature_id' => null,
                    'type_document_code' => null,
                ]);
                // Assignation explicite (piece_justificative_id volontairement
                // hors $fillable — jamais modifiable via une requête candidat).
                $experience->piece_justificative_id = $nouvelle->id;
                $experience->save();

                return $nouvelle;
            });
        } catch (\Throwable $e) {
            $this->stockage->supprimer($meta['chemin_stockage']);
            throw $e;
        }

        if ($ancienne !== null && $ancienChemin !== $meta['chemin_stockage']) {
            $this->stockage->supprimer($ancienChemin);
        }

        return (new PieceJustificativeResource($piece->load('experience')))
            ->response()
            ->setStatusCode($ancienne !== null ? 200 : 201);
    }

    /**
     * DELETE /api/candidatures/{candidature}/experiences/{experience}/justificatif
     */
    public function destroy(
        Candidature $candidature,
        ExperienceProfessionnelle $experience,
    ): Response {
        $this->authorize('update', $candidature);

        $piece = $experience->pieceJustificative;
        abort_if($piece === null, 404, 'Aucun justificatif pour cette expérience.');

        $chemin = $piece->chemin_stockage;
        DB::transaction(function () use ($experience, $piece) {
            $experience->piece_justificative_id = null;
            $experience->save();
            $piece->delete();
        });
        $this->stockage->supprimer($chemin);

        return response()->noContent();
    }
}
