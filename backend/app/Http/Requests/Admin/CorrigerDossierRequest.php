<?php

namespace App\Http\Requests\Admin;

use App\Domain\Candidature\ChampsFormulaire;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correction exceptionnelle du volet DOSSIER (Lot 6b) — administrateur strict.
 *
 *   POST /api/admin/candidatures/{candidature}/correction/dossier
 *   {
 *     motif: "...",                          // OBLIGATOIRE
 *     reponses: { sc01_...: "...", ... },    // champs scoreables de reponse_formulaire (+ mo04_note_etoiles)
 *     verification: { nationalite_confirmee, diplome_verifie },
 *     commentaire_evaluateur: "..."
 *   }
 *
 * La surface est LARGE (réponses candidat + vérification + notation) : la
 * correction sert à rectifier une erreur constatée sur pièce. La garantie n'est
 * pas dans le périmètre mais dans le `motif` obligatoire + l'audit ancien→nouveau
 * (chaque champ modifié apparaît dans la ligne d'audit).
 */
class CorrigerDossierRequest extends FormRequest
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
        $regles = [
            'motif' => ['required', 'string', 'min:3', 'max:2000'],
            'reponses' => ['sometimes', 'array'],
            'reponses.mo04_note_etoiles' => ['sometimes', 'nullable', 'integer', 'between:0,5'],
            'verification' => ['sometimes', 'array'],
            'verification.nationalite_confirmee' => ['sometimes', 'nullable', 'boolean'],
            'verification.diplome_verifie' => ['sometimes', 'nullable', Rule::in(['cepe', 'cap', 'bepc', 'bac', 'bt_bep'])],
            'commentaire_evaluateur' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];

        foreach (ChampsFormulaire::ENUMS as $champ => $valeurs) {
            $regles['reponses.'.$champ] = ['sometimes', 'nullable', 'string', Rule::in($valeurs)];
        }
        foreach (ChampsFormulaire::NIVEAUX as $champ) {
            $regles['reponses.'.$champ] = ['sometimes', 'nullable', 'integer', 'between:0,3'];
        }
        foreach (ChampsFormulaire::TEXTE as $champ => $max) {
            $regles['reponses.'.$champ] = ['sometimes', 'nullable', 'string', 'max:'.$max];
        }

        return $regles;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motif.required' => 'Un motif est obligatoire pour une correction exceptionnelle.',
            'motif.min' => 'Le motif doit être explicite (au moins 3 caractères).',
        ];
    }

    /**
     * Sous-ensemble `reponses` réellement fourni (champs `reponse_formulaire`).
     *
     * @return array<string, mixed>
     */
    public function reponses(): array
    {
        return $this->validated('reponses', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function verification(): array
    {
        return $this->validated('verification', []);
    }
}
