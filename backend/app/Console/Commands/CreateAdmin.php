<?php

namespace App\Console\Commands;

use App\Models\JournalAudit;
use App\Models\MembreEquipe;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Crée le PREMIER compte administrateur en production (bootstrap).
 *
 *   php artisan casa:create-admin coordination@casa.example.org
 *
 * `ComptesDemoSeeder` (dev) est le seul autre chemin — et il n'est jamais joué
 * en prod (`casa:seed-referentiel`). Cette commande transforme un acte risqué
 * (INSERT SQL à la main) en opération sûre : mot de passe validé comme à
 * l'inscription (min:10 + casse + chiffres), e-mail unique refusé, transaction,
 * ligne d'audit.
 */
class CreateAdmin extends Command
{
    protected $signature = 'casa:create-admin {email : Adresse e-mail de connexion}';

    protected $description = 'Crée un compte administrateur (utilisateur + membre_equipe)';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        $emailCheck = Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']]);
        if ($emailCheck->fails()) {
            $this->error($emailCheck->errors()->first('email'));

            return self::INVALID;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error("Un compte existe déjà pour « {$email} ».");

            return self::FAILURE;
        }

        $motDePasse = (string) $this->secret('Mot de passe (min. 10 caractères, majuscule + minuscule + chiffre)');
        $confirmation = (string) $this->secret('Confirmer le mot de passe');

        $pwdCheck = Validator::make(
            ['password' => $motDePasse, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'string', 'confirmed', Password::min(10)->letters()->numbers()->mixedCase()]],
        );
        if ($pwdCheck->fails()) {
            $this->error($pwdCheck->errors()->first('password'));

            return self::INVALID;
        }

        $prenom = trim((string) $this->ask('Prénom'));
        $nom = trim((string) $this->ask('Nom'));
        $poste = trim((string) $this->ask('Poste', 'Administrateur'));

        $identiteCheck = Validator::make(
            compact('prenom', 'nom', 'poste'),
            [
                'prenom' => ['required', 'string', 'max:100'],
                'nom' => ['required', 'string', 'max:100'],
                'poste' => ['required', 'string', 'max:100'],
            ],
        );
        if ($identiteCheck->fails()) {
            $this->error($identiteCheck->errors()->first());

            return self::INVALID;
        }

        $user = DB::transaction(function () use ($email, $motDePasse, $prenom, $nom, $poste) {
            $user = User::create([
                'email' => $email,
                'mot_de_passe_hash' => $motDePasse,   // cast 'hashed' -> bcrypt
                'role' => 'administrateur',
                'actif' => true,
                'cgu_acceptees_le' => now(),
            ]);

            MembreEquipe::create([
                'utilisateur_id' => $user->id,
                'prenom' => $prenom,
                'nom' => $nom,
                'poste' => $poste,
            ]);

            JournalAudit::create([
                'auteur_id' => $user->id,   // auto-provisionné : l'admin est son propre auteur
                'role' => 'administrateur',
                'action' => 'Création de compte administrateur (console)',
                'module' => 'Utilisateurs',
                'objet' => $email,
                'nouvelle_valeur' => "role=administrateur, {$prenom} {$nom}",
                'resultat' => 'Succès',
            ]);

            return $user;
        });

        $this->info("Administrateur créé : {$prenom} {$nom} <{$email}> (id {$user->id}).");
        $this->line('Connexion : '.config('app.url').'/connexion');

        return self::SUCCESS;
    }
}
