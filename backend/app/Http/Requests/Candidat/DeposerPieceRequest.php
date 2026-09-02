<?php

namespace App\Http\Requests\Candidat;

use App\Domain\Piece\ContraintesFichier;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation d'un upload de pièce (dossier ou justificatif d'expérience).
 * Champ multipart : `fichier`.
 *
 * `max` est vérifié EN PREMIER (dépassement de taille -> 422 sans lire le
 * contenu). `mimetypes` valide le MIME réel détecté par finfo (le contenu),
 * pas le Content-Type déclaré ni l'extension — falsification impossible.
 * `extensions` est un garde-fou supplémentaire sur le nom client.
 */
class DeposerPieceRequest extends FormRequest
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
            'fichier' => [
                'required',
                'file',
                'max:'.ContraintesFichier::TAILLE_MAX_KO,
                'mimetypes:'.implode(',', ContraintesFichier::MIMES),
                'extensions:'.implode(',', ContraintesFichier::EXTENSIONS),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fichier.required' => 'Aucun fichier reçu (champ « fichier »).',
            'fichier.file' => 'Le contenu envoyé n\'est pas un fichier valide.',
            'fichier.max' => 'Le fichier dépasse la taille maximale de 10 Mo.',
            'fichier.mimetypes' => 'Format non autorisé : seuls PDF, JPEG et PNG sont acceptés.',
            'fichier.extensions' => 'Extension non autorisée : seuls .pdf, .jpg, .jpeg et .png sont acceptés.',
        ];
    }
}
