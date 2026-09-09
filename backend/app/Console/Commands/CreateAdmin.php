<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ProvisionnementMembreEquipe;

/**
 * Crée un compte ADMINISTRATEUR en production (bootstrap du premier compte).
 *
 *   php artisan casa:create-admin coordination@casa.example.org
 *
 * Comportement INCHANGÉ depuis le Lot 9c (signature, prompts, codes de sortie,
 * ligne d'audit). Depuis le Lot 11a, la logique est portée par
 * {@see CreerMembreEquipeCommand} + {@see ProvisionnementMembreEquipe},
 * partagés avec `casa:create-membre` (qui crée aussi des évaluateurs).
 *
 * `ComptesDemoSeeder` (dev) est le seul autre chemin — jamais joué en prod
 * (`casa:seed-referentiel`).
 */
class CreateAdmin extends CreerMembreEquipeCommand
{
    protected $signature = 'casa:create-admin {email : Adresse e-mail de connexion}';

    protected $description = 'Crée un compte administrateur (utilisateur + membre_equipe)';

    protected function roleCible(): string
    {
        return 'administrateur';
    }

    protected function posteParDefaut(): string
    {
        return 'Administrateur';
    }

    protected function messageSucces(string $prenom, string $nom, string $email, string $role, User $user): string
    {
        return "Administrateur créé : {$prenom} {$nom} <{$email}> (id {$user->id}).";
    }
}
