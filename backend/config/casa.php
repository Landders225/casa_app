<?php

/**
 * Réglages CASA propres à l'application (pas un fichier de config Laravel
 * standard). Seul point d'entrée légitime pour `env()` sur ces valeurs :
 * `AppServiceProvider::boot()` les lit via `config('casa....')`, JAMAIS via
 * `env()` directement — `env()` hors d'un fichier `config/*.php` retourne
 * `null` une fois `config:cache` exécuté (l'entrypoint le relance à chaque
 * boot, Lot 9c), ce qui aurait rendu ces seuils silencieusement inopérants
 * en production.
 *
 * `rate_limits` — seuils des limiteurs qui acceptent un ajustement
 * opérationnel (inscriptions/connexions en groupe depuis une même IP
 * publique). `casa-api`/`casa-uploads`/`casa-candidatures`/
 * `casa-mot-de-passe` restent des littéraux dans `AppServiceProvider` —
 * volontairement non exposés ici, hors périmètre de cet ajustement.
 */
return [
    'rate_limits' => [
        // Inscriptions (anti-bot), par IP — 2 clauses indépendantes.
        'register_per_minute' => (int) env('RATE_LIMIT_REGISTER_PER_MINUTE', 40),
        'register_per_day' => (int) env('RATE_LIMIT_REGISTER_PER_DAY', 600),

        // Connexion : anti-bruteforce PAR COMPTE (clé email+IP, inchangé en
        // valeur mais désormais configurable) + plafond PAR IP SEULE (contre
        // un robot qui tourne sur de nombreux comptes depuis une même IP).
        'login_per_minute' => (int) env('RATE_LIMIT_LOGIN_PER_MINUTE', 5),
        'login_ip_per_minute' => (int) env('RATE_LIMIT_LOGIN_IP_PER_MINUTE', 120),

        // Routes publiques non authentifiées (/api/health, /api/filieres), par IP.
        'public_per_minute' => (int) env('RATE_LIMIT_PUBLIC_PER_MINUTE', 600),
    ],
];
