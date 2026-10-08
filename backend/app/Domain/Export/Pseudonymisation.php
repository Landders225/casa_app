<?php

namespace App\Domain\Export;

/**
 * Pseudonymisation des identifiants de candidature pour l'export d'analyse
 * (`casa:export-analyse`) — HMAC-SHA256 clé(APP_KEY), jamais l'UUID brut.
 *
 * Pseudonyme, PAS anonyme : stable (même candidature => même `id_pseudonyme`
 * à chaque export), donc ré-identifiable par qui détient l'UUID source (la
 * base) — la protection réelle est la diffusion restreinte du fichier
 * produit, pas l'irréversibilité mathématique (cf. Dictionnaire de l'export).
 */
final class Pseudonymisation
{
    private const SEL = 'casa-export-v1';

    /** 16 hex = 64 bits : largement suffisant pour éviter une collision sur un volume de quelques milliers de candidatures. */
    private const LONGUEUR_HEX = 16;

    public static function idPseudonyme(string $candidatureId): string
    {
        $empreinte = hash_hmac('sha256', $candidatureId, self::SEL.'|'.config('app.key'));

        return 'C-'.substr($empreinte, 0, self::LONGUEUR_HEX);
    }
}
