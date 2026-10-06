<?php

namespace App\Mail;

/**
 * Normalise `MAIL_PEER_FINGERPRINT` (épinglage de certificat SMTP, serveur à
 * certificat AUTO-SIGNÉ — ex. mail.cci.ci) vers le format que PHP accepte
 * réellement pour l'option SSL `peer_fingerprint`.
 *
 * Extrait de `config/mail.php` (pas inline) pour rester testable sans avoir à
 * rejouer le chargement du fichier de config avec des `env()` différents —
 * cette fonction est pure, aucune dépendance au framework.
 */
final class PeerFingerprint
{
    /**
     * `null` si `$raw` est vide (comportement SMTP standard inchangé) ;
     * sinon `['sha256' => <hex minuscule sans séparateur>]`.
     *
     * ⚠️ Le format TABLEAU est OBLIGATOIRE, pas une chaîne nue : vérifié en
     * conditions réelles (PHP 8.3, serveur SMTP à certificat auto-signé),
     * une chaîne hex nue de 64 caractères échoue (« peer_fingerprint match
     * failure ») même avec la bonne empreinte, contrairement à ce que
     * suggère la documentation PHP sur la détection par longueur de chaîne.
     *
     * @return array{sha256: string}|null
     */
    public static function normalize(string $raw): ?array
    {
        $hex = strtolower(str_replace(':', '', trim($raw)));

        return $hex === '' ? null : ['sha256' => $hex];
    }
}
