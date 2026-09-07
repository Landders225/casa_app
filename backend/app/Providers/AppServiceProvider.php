<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // --- Limitation des tentatives de connexion (5 / min par e-mail + IP) ---
        RateLimiter::for('login', function (Request $request) {
            $cle = Str::lower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinute(5)->by($cle);
        });

        // --- Limitation des inscriptions (anti-bot) : 3 / min ET 20 / jour par IP ---
        RateLimiter::for('register', fn (Request $request) => [
            Limit::perMinute(3)->by('register|'.$request->ip()),
            Limit::perDay(20)->by('register-day|'.$request->ip()),
        ]);

        // --- Filet global des routes authentifiées (Lot 10, T1) -----------------
        // 120 req/min par UTILISATEUR (id de session ; repli IP). Très au-dessus
        // de l'usage réel le plus intense — le wizard de candidature fait ~10
        // PATCH + quelques POST sur 10 min. But : casser un script qui martèle,
        // pas gêner un humain. Les actes UNIQUES (publier, valider une
        // évaluation) ne consomment qu'une requête : jamais de 429 en usage
        // normal. La vraie défense des actes admin reste le rôle strict + le
        // journal d'audit immuable (ADR-12), pas un compteur.
        RateLimiter::for('casa-api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // --- Dépôt de pièces : 40 / min (finfo + écriture disque par requête) ---
        RateLimiter::for('casa-uploads', fn (Request $request) => Limit::perMinute(40)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // --- Création de candidatures : 12 / min (anti-spam de brouillons) -----
        RateLimiter::for('casa-candidatures', fn (Request $request) => Limit::perMinute(12)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // --- Routes PUBLIQUES non authentifiées : 60 / min par IP -------------
        // `/api/health` et `/api/filieres` sont hors du filet `casa-api` (pas de
        // session) — ce sont les seules portes ouvertes aux non-authentifiés.
        RateLimiter::for('casa-public', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // --- Autorisations sémantiques (fondation ADR-10) ---
        // Middleware `role:` pour protéger les routes ; ces Gates pour les
        // autorisations fines dans les contrôleurs des lots suivants.
        Gate::define('acceder-evaluation', fn (User $user) => $user->hasRole('evaluateur', 'administrateur'));
        Gate::define('administrer', fn (User $user) => $user->isAdministrateur());
    }
}
