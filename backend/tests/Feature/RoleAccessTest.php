<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Matrice de filtrage par rôle sur les routes de démonstration (ADR-10).
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0:string, 1:array<string,int>}>
     */
    public static function matrice(): array
    {
        return [
            // rôle            ping-candidat  ping-evaluateur  ping-admin
            'candidat' => ['candidat', [
                '/api/ping-candidat' => 200,
                '/api/ping-evaluateur' => 403,
                '/api/ping-admin' => 403,
            ]],
            'evaluateur' => ['evaluateur', [
                '/api/ping-candidat' => 403,
                '/api/ping-evaluateur' => 200,
                '/api/ping-admin' => 403,
            ]],
            'administrateur' => ['administrateur', [
                '/api/ping-candidat' => 403,
                '/api/ping-evaluateur' => 200, // admin ⊇ évaluateur
                '/api/ping-admin' => 200,
            ]],
        ];
    }

    /**
     * @param  array<string,int>  $attendus
     */
    #[DataProvider('matrice')]
    public function test_role_matrix(string $role, array $attendus): void
    {
        $user = User::factory()->state(['role' => $role])->create();

        foreach ($attendus as $route => $status) {
            $this->actingAs($user)->getJson($route)->assertStatus($status);
        }
    }

    public function test_ping_routes_require_authentication(): void
    {
        $this->getJson('/api/ping-candidat')->assertStatus(401);
        $this->getJson('/api/ping-evaluateur')->assertStatus(401);
        $this->getJson('/api/ping-admin')->assertStatus(401);
    }
}
