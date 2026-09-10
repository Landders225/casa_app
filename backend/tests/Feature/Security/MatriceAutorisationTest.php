<?php

namespace Tests\Feature\Security;

use App\Models\Campagne;
use App\Models\Candidat;
use App\Models\Candidature;
use App\Models\Filiere;
use App\Models\User;
use Database\Seeders\CampagneSeeder;
use Database\Seeders\FiliereSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * MATRICE D'AUTORISATION EXHAUSTIVE (Lot 10) — preuve systématique.
 *
 * Aucune liste de routes en dur : le test introspecte la table de routage,
 * repère chaque route portant le middleware `role:` et vérifie, pour CHACUNE :
 *   - un rôle NON autorisé            → 403 ou 404, JAMAIS un 2xx ni un code
 *                                        « traité » (422/409) ;
 *   - un utilisateur NON authentifié  → 401 ;
 *   - un rôle autorisé                → ni 401 ni 403 (200 / 404 / 409 / 422
 *                                        selon le contexte absent — hors sujet).
 *
 * Pourquoi 403 OU 404 pour un rôle interdit : sur les routes à paramètre lié
 * (`{candidature}`, `{campagne}`…), le middleware de groupe `SubstituteBindings`
 * s'exécute avant le middleware de route `role:`. Avec un id inexistant (ce
 * test) le binding échoue d'abord → 404 ; avec un id réel, `role:` rejette →
 * 403 (cf. test_actes_admin_exceptionnels_403_avec_ressource_reelle). Les deux
 * codes sont non-divulgants ; le seul « oracle » résiduel (403 vs 404 = la
 * ressource existe ou non) est neutralisé par des UUID de 122 bits
 * non énumérables — tracé comme risque très faible dans docs/AUDIT-SECURITE.md.
 *
 * `admin ⊇ évaluateur` (ADR-10) est couvert nativement : les routes évaluateur
 * déclarent `role:evaluateur,administrateur`.
 *
 * L'isolation par PROPRIÉTAIRE (dossier d'autrui → 404, pas 403) est prouvée
 * ailleurs : IsolationCandidatureTest, IsolationPieceTest, IsolationEvaluateurTest.
 */
class MatriceAutorisationTest extends TestCase
{
    use RefreshDatabase;

    private const UUID_BIDON = '00000000-0000-0000-0000-000000000000';

    /** @return list<string>|null Rôles autorisés, ou null si la route n'est pas `role:`-gardée. */
    private function rolesAutorises(RoutingRoute $route): ?array
    {
        foreach ($route->gatherMiddleware() as $mw) {
            if (is_string($mw) && preg_match('/(?:EnsureUserHasRole|\brole):([a-z,]+)/', $mw, $m)) {
                return explode(',', $m[1]);
            }
        }

        return null;
    }

    private function url(string $uri): string
    {
        return '/'.preg_replace_callback(
            '/\{[^}]+\}/',
            fn ($m) => str_contains($m[0], 'type') ? 'cni' : self::UUID_BIDON,
            $uri,
        );
    }

    public function test_matrice_role_x_route_exhaustive(): void
    {
        $acteurs = [
            'candidat' => User::factory()->create(),
            'evaluateur' => User::factory()->evaluateur()->create(),
            'administrateur' => User::factory()->administrateur()->create(),
        ];

        $routes = collect(Route::getRoutes())
            ->filter(fn (RoutingRoute $r) => str_starts_with($r->uri(), 'api/'))
            ->filter(fn (RoutingRoute $r) => $this->rolesAutorises($r) !== null);

        $this->assertGreaterThanOrEqual(35, $routes->count(), 'Introspection cassée : trop peu de routes role:-gardées.');

        $cibles = [];
        foreach ($routes as $route) {
            $rolesOk = $this->rolesAutorises($route);
            foreach ($route->methods() as $methode) {
                if (in_array($methode, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $cibles[] = [strtolower($methode), $this->url($route->uri()), "{$methode} {$route->uri()}", $rolesOk];
            }
        }

        $violations = [];

        // Passe 1 — invité (aucun `actingAs` avant, sinon l'état d'auth persiste
        // sur $this et fausse le contrôle).
        foreach ($cibles as [$verbe, $url, $etiquette]) {
            $code = $this->json($verbe, $url, [])->getStatusCode();
            if ($code !== 401) {
                $violations[] = "{$etiquette} — invité : attendu 401, reçu {$code}";
            }
        }

        // Passe 2 — un acteur à la fois (chaque `actingAs` écrase le précédent).
        foreach ($acteurs as $role => $acteur) {
            foreach ($cibles as [$verbe, $url, $etiquette, $rolesOk]) {
                $code = $this->actingAs($acteur)->json($verbe, $url, [])->getStatusCode();
                $autorise = in_array($role, $rolesOk, true);

                if ($autorise && in_array($code, [401, 403], true)) {
                    $violations[] = "{$etiquette} — rôle autorisé « {$role} » : reçu {$code} (ne devrait jamais être 401/403)";
                }
                if (! $autorise && ! in_array($code, [403, 404], true)) {
                    $violations[] = "{$etiquette} — rôle interdit « {$role} » : attendu 403 ou 404, reçu {$code}";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Matrice d'autorisation — ".count($violations)." violation(s) :\n".implode("\n", $violations),
        );
    }

    public function test_les_familles_de_routes_sont_toutes_representees(): void
    {
        $familles = collect(Route::getRoutes())
            ->filter(fn (RoutingRoute $r) => str_starts_with($r->uri(), 'api/'))
            ->map(fn (RoutingRoute $r) => $this->rolesAutorises($r))
            ->filter()
            ->map(fn ($r) => implode(',', $r))
            ->unique()
            ->values();

        $this->assertContains('candidat', $familles);
        $this->assertContains('evaluateur,administrateur', $familles);
        $this->assertContains('administrateur', $familles);
    }

    public function test_les_routes_authentifiees_sans_role_exigent_une_session(): void
    {
        $this->postJson('/api/logout')->assertStatus(401);
        $this->getJson('/api/me')->assertStatus(401);
    }

    /**
     * Preuve du 403 EXACT (pas 404) sur les routes les plus sensibles, avec une
     * ressource RÉELLE : le binding réussit, puis `role:` rejette.
     */
    public function test_actes_admin_exceptionnels_403_avec_ressource_reelle(): void
    {
        $this->seed([FiliereSeeder::class, CampagneSeeder::class]);
        $campagne = Campagne::query()->firstOrFail();
        $filiere = Filiere::query()->firstOrFail();

        $candidat = User::factory()->create();
        Candidat::create([
            'utilisateur_id' => $candidat->id,
            'prenom' => 'A', 'nom' => 'B', 'sexe' => 'F', 'date_naissance' => '2004-01-01',
            'cni' => 'CI999999999', 'telephone' => '0700000000',
            'ville_residence' => 'Abidjan', 'residence_ci' => true,
        ]);
        $candidature = Candidature::create([
            'candidat_id' => $candidat->candidat->id,
            'campagne_id' => $campagne->id,
            'filiere_id' => $filiere->id,
            'numero_dossier' => 'CASA-TEST-0001',
        ]);

        // Membre d'équipe réel : le binding {utilisateur} réussit, puis `role:`
        // rejette → 403 EXACT sur les routes de gestion des comptes (Lot 11b).
        $membre = User::factory()->evaluateur()->create();

        $routesSensibles = [
            ['post', "/api/admin/candidatures/{$candidature->id}/correction/dossier"],
            ['post', "/api/admin/candidatures/{$candidature->id}/correction/entretien"],
            ['post', "/api/admin/candidatures/{$candidature->id}/elimination"],
            ['put', "/api/admin/candidatures/{$candidature->id}/decision/motifs"],
            ['post', "/api/admin/campagnes/{$campagne->id}/publier"],
            ['post', "/api/admin/campagnes/{$campagne->id}/classement"],
            ['patch', "/api/admin/campagnes/{$campagne->id}"],
            ['patch', "/api/admin/filieres/{$filiere->id}"],
            ['get', '/api/admin/membres'],
            ['post', '/api/admin/membres'],
            ['patch', "/api/admin/membres/{$membre->id}"],
            ['post', "/api/admin/membres/{$membre->id}/mot-de-passe"],
        ];

        foreach (['candidat', 'evaluateur'] as $roleInterdit) {
            $acteur = $roleInterdit === 'candidat'
                ? User::factory()->create()
                : User::factory()->evaluateur()->create();

            foreach ($routesSensibles as [$verbe, $url]) {
                $this->actingAs($acteur)->json($verbe, $url, [])
                    ->assertStatus(403);
            }
        }
    }
}
