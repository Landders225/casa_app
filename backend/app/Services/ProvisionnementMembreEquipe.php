<?php

namespace App\Services;

use App\Models\JournalAudit;
use App\Models\MembreEquipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * Création d'un compte MEMBRE D'ÉQUIPE (évaluateur OU administrateur) — socle
 * partagé par `casa:create-membre` et `casa:create-admin` (Lot 11a).
 *
 * Le sous-type exact vit sur `utilisateur.role` (ADR-10), jamais dupliqué dans
 * `membre_equipe`. Règle de mot de passe IDENTIQUE à l'inscription (ADR-16 :
 * `Password::min(10)->letters()->numbers()->mixedCase()`).
 *
 * Ne crée JAMAIS un `candidat` : les rôles autorisés par cette voie sont
 * strictement {evaluateur, administrateur} (`ROLES`) — triple garde : défaut
 * moins-privilégié côté commande, `Rule::in` ici, + le CHECK PostgreSQL
 * `utilisateur_role_check` en dernier rempart.
 */
class ProvisionnementMembreEquipe
{
    /** @var list<string> */
    public const ROLES = ['evaluateur', 'administrateur'];

    /**
     * Mot de passe — IDENTIQUE à l'inscription (ADR-16). `confirmed` compare
     * `password` à `password_confirmation`. Validé SEUL, juste après la double
     * saisie, avant de demander l'identité (comme l'ancien `casa:create-admin`).
     *
     * @return list<mixed>
     */
    public static function motDePasseRules(): array
    {
        return ['required', 'string', 'confirmed', Password::min(10)->letters()->numbers()->mixedCase()];
    }

    /**
     * Identité du membre d'équipe.
     *
     * @return array<string, list<string>>
     */
    public static function identiteRules(): array
    {
        return [
            'prenom' => ['required', 'string', 'max:100'],
            'nom' => ['required', 'string', 'max:100'],
            'poste' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
        ];
    }

    /**
     * Crée `utilisateur` + `membre_equipe` + 1 ligne d'audit, dans une seule
     * transaction. L'appelant a déjà validé (`rules()`) et garanti l'unicité
     * de l'e-mail.
     *
     * @param  array{email:string, role:string, password:string, prenom:string, nom:string, poste:string}  $data
     */
    public function creer(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'email' => $data['email'],
                'mot_de_passe_hash' => $data['password'], // cast 'hashed' -> bcrypt
                'role' => $data['role'],
                'actif' => true,
                'cgu_acceptees_le' => now(),
            ]);

            MembreEquipe::create([
                'utilisateur_id' => $user->id,
                'prenom' => $data['prenom'],
                'nom' => $data['nom'],
                'poste' => $data['poste'],
            ]);

            JournalAudit::create([
                'auteur_id' => $user->id, // self-provisionné : pas d'acteur authentifié en CLI, FK NOT NULL
                'role' => $data['role'],
                'action' => "Création de compte {$data['role']} (console)",
                'module' => 'Utilisateurs',
                'objet' => $data['email'],
                'nouvelle_valeur' => "role={$data['role']}, {$data['prenom']} {$data['nom']}",
                'resultat' => 'Succès',
            ]);

            return $user;
        });
    }
}
