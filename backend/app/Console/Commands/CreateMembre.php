<?php

namespace App\Console\Commands;

/**
 * Crée un compte MEMBRE D'ÉQUIPE — évaluateur (défaut) ou administrateur (Lot 11a).
 *
 *   php artisan casa:create-membre alice@cci.ci                       # évaluateur
 *   php artisan casa:create-membre bob@cci.ci --role=administrateur   # administrateur
 *
 * Débloque la constitution du jury en production : `casa:create-admin` ne crée
 * qu'un administrateur, et `ComptesDemoSeeder` (qui crée `evaluateur@…`) n'est
 * jamais joué en prod.
 *
 * `--role` par défaut = `evaluateur` (l'acte répété, ET le rôle le moins
 * privilégié : un oubli crée un évaluateur, jamais un admin par inadvertance).
 * Seuls `evaluateur` et `administrateur` sont acceptés — `candidat` ou tout
 * autre valeur est rejeté (exit 2) sans aucune écriture.
 */
class CreateMembre extends CreerMembreEquipeCommand
{
    protected $signature = 'casa:create-membre
        {email : Adresse e-mail de connexion}
        {--role=evaluateur : Rôle du compte — « evaluateur » (défaut) ou « administrateur »}';

    protected $description = 'Crée un compte évaluateur ou administrateur (utilisateur + membre_equipe)';

    protected function roleCible(): string
    {
        return strtolower(trim((string) $this->option('role')));
    }

    protected function posteParDefaut(): string
    {
        return $this->roleCible() === 'administrateur' ? 'Administrateur' : 'Évaluateur';
    }
}
