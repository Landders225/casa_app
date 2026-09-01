<?php

namespace App\Http\Requests\Candidat;

use App\Models\Candidature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Remplace le classement des filières par préférence. Contrat :
 * `{ "ordre": [filiere_id, ...] }` — exactement les 5 filières de la campagne,
 * sans doublon. Le rang = position dans le tableau + 1 (1 = préférée).
 */
class ClassementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Candidature $candidature */
        $candidature = $this->route('candidature');

        return [
            'ordre' => ['required', 'array', 'size:5'],
            'ordre.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('campagne_filiere', 'filiere_id')
                    ->where('campagne_id', $candidature->campagne_id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ordre.size' => 'Le classement doit contenir exactement les 5 filières de la campagne.',
            'ordre.*.distinct' => 'Une filière est présente en double dans le classement.',
            'ordre.*.exists' => "Une filière du classement n'appartient pas à cette campagne.",
        ];
    }
}
