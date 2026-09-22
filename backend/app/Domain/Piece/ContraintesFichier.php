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

    /**
     * Les 5 types de pièces OBLIGATOIRES du dossier (référentiel
     * `type_document`) — `residence`/`lettre` retirés au Lot D (plus jamais
     * exigés NI proposés au dépôt), `cmu` ajouté au Lot 18. SOURCE UNIQUE
     * consommée par `ValidateurCompletude` (diff des pièces manquantes) et
     * par le wizard (`PIECES_DOSSIER`, frontend) : y ajouter/retirer un type
     * suffit à changer l'obligation de bout en bout, rien d'autre à modifier
     * côté `ValidateurCompletude`.
     */
    public const TYPES_DOSSIER = ['cni', 'diplome', 'cv', 'photo', 'cmu'];

    /**
     * Types RETIRÉS de l'obligation (Lot D) mais dont le référentiel
     * `type_document` garde la ligne (intégrité FK avec tout
     * `piece_justificative` déjà déposé — on ne détruit jamais un dépôt
     * candidat). Sert UNIQUEMENT à composer `TYPES_ACCEPTES` ci-dessous ; ne
     * jamais lire cette constante seule pour une obligation ou un affichage
     * de dépôt (le wizard ne doit plus les proposer).
     */
    public const TYPES_RETIRES = ['residence', 'lettre'];

    /**
     * Types acceptés par la ROUTE d'upload/suppression (`whereIn`,
     * `routes/api.php`) — délibérément plus large que `TYPES_DOSSIER` :
     * un candidat qui a DÉJÀ déposé `residence`/`lettre` avant le Lot D doit
     * pouvoir encore la remplacer ou la retirer via l'endpoint existant,
     * même si plus personne ne peut en déposer une de zéro (le wizard ne
     * propose plus le bloc). Jamais utilisée pour la complétude.
     */
    public const TYPES_ACCEPTES = [...self::TYPES_DOSSIER, ...self::TYPES_RETIRES];
}
