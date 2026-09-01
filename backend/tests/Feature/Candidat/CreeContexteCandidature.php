<?php

namespace Tests\Feature\Candidat;

use App\Models\Candidat;
use App\Models\User;
use Database\Seeders\CampagneSeeder;
use Database\Seeders\FiliereSeeder;

/**
 * Contexte partagé des tests candidature Lot 3a : filières + campagne « Cohorte 1 »
 * (ouverte) seedées, et fabrique de comptes candidat complets.
 */
trait CreeContexteCandidature
{
    protected function seedReferentiels(): void
    {
        $this->seed([FiliereSeeder::class, CampagneSeeder::class]);
    }

    /**
     * Compte candidat + profil `candidat` prêt à candidater.
     */
    protected function creerCandidat(string $email = null): User
    {
        $user = User::factory()->create([
            'role' => 'candidat',
            'email' => $email ?? fake()->unique()->safeEmail(),
        ]);

        Candidat::create([
            'utilisateur_id' => $user->id,
            'prenom' => 'Test',
            'nom' => 'Candidat',
            'sexe' => 'F',
            'date_naissance' => '2004-01-01',
            'cni' => 'CI'.fake()->numerify('#########'),
            'telephone' => '0700000000',
            'ville_residence' => 'Abidjan - Cocody',
            'residence_ci' => true,
        ]);

        return $user->fresh();
    }

    protected function idFiliere(string $code): string
    {
        return \App\Models\Filiere::where('code', $code)->value('id');
    }
}
