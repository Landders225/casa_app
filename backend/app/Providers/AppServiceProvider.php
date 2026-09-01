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

        // --- Autorisations sémantiques (fondation ADR-10) ---
        // Middleware `role:` pour protéger les routes ; ces Gates pour les
        // autorisations fines dans les contrôleurs des lots suivants.
        Gate::define('acceder-evaluation', fn (User $user) => $user->hasRole('evaluateur', 'administrateur'));
        Gate::define('administrer', fn (User $user) => $user->isAdministrateur());
    }
}
