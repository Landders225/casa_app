<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Édition des quotas par filière d'une campagne (Lot 17, D-6a-2, ADR-09) —
 * administrateur strict.
 *
 *   PUT /api/admin/campagnes/{campagne}/quotas
 *   { quotas: [{ filiere_id, quota }] }
 *
 * `quotas` doit couvrir EXACTEMENT les filières déjà rattachées à la
 * campagne — ni plus (pas d'attachement d'une nouvelle filière, hors
 * périmètre : ça se fait uniquement à la création), ni moins (un lot
 * partiel serait ambigu : la filière omise garde-t-elle son quota, ou
 * est-elle retirée ? on ne devine pas, l'écran envoie l'état complet du
 * panneau qu'il affiche).
 *
 * Le garde-fou métier (bloqué si publiée, marque `classement_perime` si un
 * classement existait déjà) est appliqué dans le contrôleur, pas ici —
 * conforme au découpage forme (422) / métier (409) du reste du projet.
 */
class ModifierQuotasRequest extends FormRequest
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
            'quotas' => ['required', 'array', 'min:1'],
            'quotas.*.filiere_id' => ['required', 'distinct', Rule::exists('filiere', 'id')],
            'quotas.*.quota' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quotas.required' => 'Au moins une filière est attendue.',
            'quotas.*.filiere_id.distinct' => 'Une même filière ne peut apparaître qu’une seule fois.',
            'quotas.*.quota.min' => 'Le quota ne peut pas être négatif.',
        ];
    }

    /**
     * Le lot doit couvrir EXACTEMENT les filières déjà rattachées à la
     * campagne (route-bindée) — ni oubli, ni filière étrangère à la campagne.
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            $campagne = $this->route('campagne');
            if ($campagne === null || $validator->errors()->isNotEmpty()) {
                return;
            }

            $attendues = $campagne->filieres()->pluck('filiere.id')->sort()->values();
            $recues = collect($this->input('quotas', []))->pluck('filiere_id')->sort()->values();

            if ($attendues->all() !== $recues->all()) {
                $validator->errors()->add(
                    'quotas',
                    'Le lot de quotas doit couvrir exactement les filières déjà rattachées à cette campagne (ni oubli, ni filière étrangère).',
                );
            }
        });
    }
}
