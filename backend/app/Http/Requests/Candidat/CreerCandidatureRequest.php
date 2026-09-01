<?php

namespace App\Http\Requests\Candidat;

use Illuminate\Foundation\Http\FormRequest;

class CreerCandidatureRequest extends FormRequest
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
        return [
            'filiere_id' => ['required', 'uuid', 'exists:filiere,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'filiere_id.required' => 'La filière visée est obligatoire.',
            'filiere_id.exists' => "Cette filière n'existe pas.",
        ];
    }
}
