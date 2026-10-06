<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\NotPwnedVerifier;
use Illuminate\Validation\ValidationServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // --- HIBP (Lot 15d) : timeout resserré à 3 s -----------------------
        // Laravel utilise 30 s par défaut — trop long pour ne pas geler
        // perceptiblement une inscription si l'API est lente. Le comportement
        // fail-open (exception réseau OU réponse non-2xx -> mot de passe
        // accepté) est NATIF à `NotPwnedVerifier`, pas modifié ici — voir
        // App\Rules\PolitiqueMotDePasse pour le détail du mécanisme.
        //
        // `ValidationServiceProvider` est un DeferrableProvider (contrat
        // Laravel 11+, plus l'ancien `$defer = true`) : `UncompromisedVerifier`
        // reste listé dans `deferredServices` même après un `singleton()` ici,
        // et est ré-enregistré (donc écrasé, retour à 30 s) dès la PREMIÈRE
        // résolution de `validator`/`validation.presence`/`UncompromisedVerifier`
        // n'importe où dans l'app — piège vérifié en écrivant le test de ce
        // lot (le timeout ressortait à 30, pas 3, sans cette ligne). On force
        // donc le chargement EAGER du provider différé D'ABORD (`loadedProviders`
        // le marque chargé, plus jamais ré-enregistré ensuite), PUIS on
        // rebinde par-dessus — dans cet ordre précis.
        //
        // `HttpFactory` bindé SINGLETON pour une raison distincte : sans ça,
        // `NotPwnedVerifier` (qui reçoit sa Factory par injection de
        // constructeur, `$app[HttpFactory::class]`) et la façade `Http::`
        // (utilisée par `Http::fake()` en test) résolvent chacune leur PROPRE
        // instance — `NotPwnedVerifier` finit avec une Factory jamais stubée
        // et tape le vrai réseau en test, piège vérifié en écrivant
        // `HibpMotDePasseTest`.
        $this->app->singleton(HttpFactory::class);
        $this->app->register(ValidationServiceProvider::class);

        $this->app->singleton(
            UncompromisedVerifier::class,
            fn ($app) => new NotPwnedVerifier($app[HttpFactory::class], 3),
        );
    }

    public function boot(): void
    {
        // --- Limitation des tentatives de connexion ------------------------------
        // Anti-bruteforce PAR COMPTE (clé email+IP, `casa.rate_limits.login_per_minute`,
        // défaut 5) + plafond PAR IP SEULE (`login_ip_per_minute`, défaut 120) :
        // un groupe qui se connecte depuis la même IP publique (même Wi-Fi) ne
        // doit pas se heurter au seuil anti-bruteforce pensé pour UN compte, mais
        // un robot qui tourne sur de nombreux comptes depuis une même IP reste
        // arrêté. Lues via `config()`, jamais `env()` ici (cf. config/casa.php).
        RateLimiter::for('login', function (Request $request) {
            $cle = Str::lower((string) $request->input('email')).'|'.$request->ip();

            return [
                Limit::perMinute((int) config('casa.rate_limits.login_per_minute'))->by($cle),
                Limit::perMinute((int) config('casa.rate_limits.login_ip_per_minute'))->by('login-ip|'.$request->ip()),
            ];
        });

        // --- Limitation des inscriptions (anti-bot) : par minute ET par jour, par IP ---
        // Seuils ajustés pour les inscriptions de groupe (même IP publique) —
        // `config('casa.rate_limits.register_*')`, défauts 40/min et 600/jour.
        RateLimiter::for('register', fn (Request $request) => [
            Limit::perMinute((int) config('casa.rate_limits.register_per_minute'))->by('register|'.$request->ip()),
            Limit::perDay((int) config('casa.rate_limits.register_per_day'))->by('register-day|'.$request->ip()),
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

        // --- Mot de passe (Lot 13, ADR-32) : demande de reset, réinitialisation,
        // changement connecté. 6/min — INCHANGÉ, hors périmètre de l'ajustement
        // groupe (Lot « rate limiting groupe »). Keyé IP quand non authentifié —
        // JAMAIS par e-mail, pour ne pas ouvrir un oracle d'énumération sur le
        // throttle lui-même. Keyé utilisateur pour le changement connecté.
        RateLimiter::for('casa-mot-de-passe', fn (Request $request) => Limit::perMinute(6)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // --- Routes PUBLIQUES non authentifiées : par IP -----------------------
        // `/api/health` et `/api/filieres` sont hors du filet `casa-api` (pas de
        // session) — ce sont les seules portes ouvertes aux non-authentifiés.
        // `config('casa.rate_limits.public_per_minute')`, défaut 600 (relevé pour
        // les groupes qui chargent la page d'accueil depuis une même IP).
        RateLimiter::for('casa-public', fn (Request $request) => Limit::perMinute((int) config('casa.rate_limits.public_per_minute'))->by($request->ip()));

        // --- Débit d'envoi des e-mails de notification (Lot 18, ADR-35) --------
        // PAS un throttle HTTP : un limiteur de JOB DE FILE, consommé par le
        // middleware `Illuminate\Queue\Middleware\RateLimited` attaché
        // UNIQUEMENT au canal `mail` des notifications (cf.
        // App\Notifications\Concerns\EnvoiMailResilient::middleware()). Clé
        // globale (pas de `->by(...)`) : UNE seule contrainte, le débit total
        // vers le serveur SMTP, pas par destinataire. `config('casa.mail_max_per_minute')`,
        // défaut 30 — la vraie limite Exchange reste à confirmer par le DSI.
        RateLimiter::for('envoi-mail-notification', fn () => Limit::perMinute((int) config('casa.mail_max_per_minute')));

        // --- Autorisations sémantiques (fondation ADR-10) ---
        // Middleware `role:` pour protéger les routes ; ces Gates pour les
        // autorisations fines dans les contrôleurs des lots suivants.
        Gate::define('acceder-evaluation', fn (User $user) => $user->hasRole('evaluateur', 'administrateur'));
        Gate::define('administrer', fn (User $user) => $user->isAdministrateur());
    }
}
