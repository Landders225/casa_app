<?php

namespace App\Domain\Piece;

/**
 * Contraintes d'upload des pièces justificatives (Lot 3b).
 *
 * Formats : PDF + JPEG + PNG (la maquette dit « PDF, JPEG » ; PNG ajouté car
 * la photo d'identité est souvent en PNG — assouplissement assumé).
 * Taille : 10 Mo par fichier (maquette).
 */
final class ContraintesFichier
{
    /** MIME réels autorisés (détectés par CONTENU, pas par Content-Type déclaré). */
    public const MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    /** Extensions clientes tolérées (garde-fou supplémentaire). */
    public const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    /** Taille max en kilo-octets (10 Mo). */
    public const TAILLE_MAX_KO = 10240;

    /** Extension serveur à donner au fichier stocké, selon le MIME détecté. */
    public const EXTENSION_PAR_MIME = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /** Les 6 types de pièces du dossier (référentiel `type_document`). */
    public const TYPES_DOSSIER = ['cni', 'residence', 'diplome', 'cv', 'lettre', 'photo'];
}
