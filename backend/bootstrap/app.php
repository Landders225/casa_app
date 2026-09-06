<?php

use App\Http\Middleware\EnsureCandidatureModifiable;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA : cookies same-origin, pas de token Bearer (ADR-01).
        $middleware->statefulApi();

        // Reverse-proxy nginx unique devant l'app (Lot 9b, ADR-27). Derrière
        // TLS, `$request->secure()` est déjà correct (fastcgi_param HTTPS=on) ;
        // ceci sécurise en plus l'IP client et le schéma si une LB/CDN vient un
        // jour en amont. `TRUSTED_PROXIES` : « * » sûr ici (backend:9000 non
        // publié, nginx écrase X-Forwarded-For) — à resserrer au sous-réseau
        // Docker en prod (cf. backend/.env.production.example). Absent = « * ».
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));

        // Contrôle de rôle réutilisable (ADR-10) : role:candidat /
        // role:evaluateur,administrateur / role:administrateur
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            // Ferme l'édition d'une candidature soumise (Lot 3c) : 404 si pas
            // propriétaire, 409 si plus en brouillon.
            'candidature.modifiable' => EnsureCandidatureModifiable::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Les routes API renvoient toujours du JSON, jamais une redirection login.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Non authentifié.'], 401);
            }
        });
    })->create();
