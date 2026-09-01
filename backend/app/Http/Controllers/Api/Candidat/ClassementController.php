<?php

namespace App\Http\Controllers\Api\Candidat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Candidat\ClassementRequest;
use App\Http\Resources\CandidatureCandidatResource;
use App\Models\Candidature;
use Illuminate\Support\Facades\DB;

class ClassementController extends Controller
{
    /**
     * PUT /api/candidatures/{candidature}/classement
     * Remplace tout le classement (transaction). rang = position + 1.
     */
    public function update(ClassementRequest $request, Candidature $candidature): CandidatureCandidatResource
    {
        $this->authorize('update', $candidature);

        $ordre = $request->validated('ordre');

        DB::transaction(function () use ($candidature, $ordre) {
            $candidature->classement()->delete();

            foreach ($ordre as $i => $filiereId) {
                $candidature->classement()->create([
                    'filiere_id' => $filiereId,
                    'rang' => $i + 1,
                ]);
            }
        });

        return new CandidatureCandidatResource(
            $candidature->load(['filiere', 'campagne', 'reponseFormulaire', 'experiences', 'classement.filiere']),
        );
    }
}
