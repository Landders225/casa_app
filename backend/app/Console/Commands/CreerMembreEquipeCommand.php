<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ProvisionnementMembreEquipe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Socle interactif partagé (Lot 11a) : collecte mot de passe + identité, valide,
 * puis délègue la création à {@see ProvisionnementMembreEquipe}.
 *
 * Sous-classes concrètes :
 *  - {@see CreateMembre}  — `casa:create-membre {email} --role=evaluateur|administrateur`
 *  - {@see CreateAdmin}   — `casa:create-admin {email}` (rôle figé, comportement inchangé)
 *
 * Codes de sortie (inchangés vs l'ancien `casa:create-admin`) :
 *  - INVALID (2) : e-mail mal formé, rôle non autorisé, mot de passe invalide ;
 *  - FAILURE (1) : e-mail déjà pris ;
 *  - SUCCESS (0) : compte créé.
 */
abstract class CreerMembreEquipeCommand extends Command
{
    public function __construct(private readonly ProvisionnementMembreEquipe $provisionnement)
    {
        parent::__construct();
    }

    /** Rôle visé — valeur BRUTE (validée par handle() contre ProvisionnementMembreEquipe::ROLES). */
    abstract protected function roleCible(): string;

    /** Valeur pré-remplie de la question « Poste ». */
    protected function posteParDefaut(): string
    {
        return 'Membre du jury';
    }

    /** Message de succès (peut être surchargé pour rester fidèle au libellé historique). */
    protected function messageSucces(string $prenom, string $nom, string $email, string $role, User $user): string
    {
        return "Compte {$role} créé : {$prenom} {$nom} <{$email}> (id {$user->id}).";
    }

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $role = $this->roleCible();

        $emailCheck = Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']]);
        if ($emailCheck->fails()) {
            $this->error($emailCheck->errors()->first('email'));

            return self::INVALID;
        }

        if (! in_array($role, ProvisionnementMembreEquipe::ROLES, true)) {
            $this->error('Le rôle doit être « evaluateur » ou « administrateur » — reçu « '.$role.' ».');

            return self::INVALID;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error("Un compte existe déjà pour « {$email} ».");

            return self::FAILURE;
        }

        // Mot de passe : double saisie puis validation IMMÉDIATE (avant de
        // demander l'identité — même enchaînement que l'ancien casa:create-admin).
        $motDePasse = (string) $this->secret('Mot de passe (min. 10 caractères, majuscule + minuscule + chiffre)');
        $confirmation = (string) $this->secret('Confirmer le mot de passe');

        $pwdCheck = Validator::make(
            ['password' => $motDePasse, 'password_confirmation' => $confirmation],
            ['password' => ProvisionnementMembreEquipe::motDePasseRules()],
            ProvisionnementMembreEquipe::messages(),
        );
        if ($pwdCheck->fails()) {
            $this->error($pwdCheck->errors()->first('password'));

            return self::INVALID;
        }

        $prenom = trim((string) $this->ask('Prénom'));
        $nom = trim((string) $this->ask('Nom'));
        $poste = trim((string) $this->ask('Poste', $this->posteParDefaut()));

        $identiteCheck = Validator::make(
            compact('prenom', 'nom', 'poste'),
            ProvisionnementMembreEquipe::identiteRules(),
        );
        if ($identiteCheck->fails()) {
            $this->error($identiteCheck->errors()->first());

            return self::INVALID;
        }

        $user = $this->provisionnement->creer([
            'email' => $email,
            'role' => $role,
            'password' => $motDePasse,
            'prenom' => $prenom,
            'nom' => $nom,
            'poste' => $poste,
        ]);

        $this->info($this->messageSucces($prenom, $nom, $email, $role, $user));
        $this->line('Connexion : '.config('app.url').'/connexion');

        return self::SUCCESS;
    }
}
