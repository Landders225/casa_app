<?php

namespace App\Http\Controllers\Api\Candidat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Candidat\MajReponsesRequest;
use App\Http\Resources\CandidatureCandidatResource;
use App\Models\Candidature;

class ReponseFormulaireController extends Controller
{
    /**
     * PATCH /api/candidatures/{candidature}/reponses
     * Merge partiel : seules les clés fournies sont écrites (null = efface).
     * Aucune journalisation du contenu (données déclaratives 🔴).
     */
    public function update(MajReponsesRequest $request, Candidature $candidature): CandidatureCandidatResource
    {
        $this->authorize('update', $candidature);

        $candidature->reponseFormulaire->fill($request->donneesReponses())->save();

        return new CandidatureCandidatResource(
            $candidature->load([
                'filiere', 'campagne', 'reponseFormulaire',
                'experiences.pieceJustificative', 'classement.filiere', 'piecesDossier',
            ]),
        );
    }
}
