<?php

namespace App\Http\Controllers\Api\Candidat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Candidat\ExperienceRequest;
use App\Http\Resources\ExperienceResource;
use App\Models\Candidature;
use App\Models\ExperienceProfessionnelle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ExperienceController extends Controller
{
    /**
     * POST /api/candidatures/{candidature}/experiences
     */
    public function store(ExperienceRequest $request, Candidature $candidature): JsonResponse
    {
        $this->authorize('update', $candidature);

        $experience = $candidature->experiences()->create($request->validated());

        return (new ExperienceResource($experience))->response()->setStatusCode(201);
    }

    /**
     * PATCH /api/candidatures/{candidature}/experiences/{experience}
     * `{experience}` est scellée sur `{candidature}` (scopeBindings) : une
     * expérience d'une autre candidature -> 404.
     */
    public function update(
        ExperienceRequest $request,
        Candidature $candidature,
        ExperienceProfessionnelle $experience,
    ): ExperienceResource {
        $this->authorize('update', $candidature);

        $experience->update($request->validated());

        return new ExperienceResource($experience->fresh());
    }

    /**
     * DELETE /api/candidatures/{candidature}/experiences/{experience}
     */
    public function destroy(Candidature $candidature, ExperienceProfessionnelle $experience): Response
    {
        $this->authorize('update', $candidature);

        $experience->delete();

        return response()->noContent();
    }
}
