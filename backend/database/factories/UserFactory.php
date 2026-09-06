<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 *
 * Aligné sur la table `utilisateur` (docs/mld.md §1) : e-mail /
 * `mot_de_passe_hash` / `role` / `actif`. Pas de `name`, `password`,
 * `remember_token`. Le mot de passe est fourni en clair : le cast `hashed`
 * du modèle le hache (mot de passe par défaut : "password").
 */
class UserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'mot_de_passe_hash' => 'password',
            'role' => 'candidat',
            'actif' => true,
        ];
    }

    public function evaluateur(): static
    {
        return $this->state(fn () => ['role' => 'evaluateur']);
    }

    public function administrateur(): static
    {
        return $this->state(fn () => ['role' => 'administrateur']);
    }

    public function inactif(): static
    {
        return $this->state(fn () => ['actif' => false]);
    }
}
