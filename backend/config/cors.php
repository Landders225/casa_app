<?php

/*
|--------------------------------------------------------------------------
| CORS — CASA
|--------------------------------------------------------------------------
| L'application est servie same-origin derrière le reverse proxy nginx unique
| (ADR-01) : le CORS n'est donc normalement pas sollicité. Cette configuration
| reste posée par hygiène (et pour un éventuel front servi depuis un autre
| port en développement), avec `supports_credentials` requis par Sanctum SPA
| (cookies).
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('FRONTEND_URL', env('APP_URL', 'http://localhost:8080')),
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
