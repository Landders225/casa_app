<?php

namespace App\Services;

use App\Http\Requests\Admin\ModifierMembreRequest;
use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Actes de gestion sur un compte d'ÉQUIPE existant (Lot 11b, ADR-29 ; édition
 * d'identité ajoutée au Lot 15a) : (dés)activation, réinitialisation du mot de
 * passe, édition d'identité. Distinct de {@see ProvisionnementMembreEquipe}
 * (création).
 *
 * Garde-fous métier de la (dés)activation, dans cet ordre :
 *  - **G2** — jamais 0 administrateur actif : refuser de désactiver le DERNIER
 *    admin actif (422). Vérifié en premier pour qu'un admin seul qui se
 *    désactive lise le message le plus parlant.
 *  - **G1** — pas d'auto-désactivation : un admin ne peut pas désactiver son
 *    propre compte (422).
 *
 * Ni suppression, ni changement de rôle : le rôle est fixé à la création. Un
 * compte a une histoire (audit append-only, ADR-12) — on le désactive.
 *
 * Chaque acte : 1 ligne `journal_audit`, `auteur_id` = l'admin connecté (le
 * VRAI acteur, pas d'auto-provisionnement ici). Le mot de passe généré n'est
 * JAMAIS journalisé — il est seulement retourné à l'appelant pour un affichage
 * unique.
 */
class GestionCompteEquipe
{
    /**
     * Passe `cible.actif` à `$actif`. No-op (sans audit) si déjà dans cet état.
     *
     * @throws HttpException 422 si un garde-fou est violé
     */
    public function definirActivation(User $cible, bool $actif, User $auteur): void
    {
        if ((bool) $cible->actif === $actif) {
            return;
        }

        if (! $actif) {
            // G2 — le dernier administrateur actif ne peut pas être désactivé.
            abort_if(
                $cible->role === 'administrateur'
                    && User::query()->where('role', 'administrateur')->where('actif', true)->count() <= 1,
                422,
                'Impossible de désactiver le dernier administrateur actif.',
            );

            // G1 — pas d'auto-désactivation.
            abort_if(
                $cible->id === $auteur->id,
                422,
                'Vous ne pouvez pas désactiver votre propre compte.',
            );
        }

        DB::transaction(function () use ($cible, $actif, $auteur) {
            $cible->forceFill(['actif' => $actif])->save();

            JournalAudit::create([
                'auteur_id' => $auteur->id,
                'role' => $auteur->role,
                'action' => $actif ? 'Activation de compte' : 'Désactivation de compte',
                'module' => 'Utilisateurs',
                'objet' => $cible->email,
                'ancienne_valeur' => 'actif='.($actif ? 'false' : 'true'),
                'nouvelle_valeur' => 'actif='.($actif ? 'true' : 'false'),
                'resultat' => 'Succès',
            ]);
        });
    }

    /**
     * Génère un nouveau mot de passe pour `cible`, l'écrit (cast `hashed`), trace
     * l'acte SANS aucune valeur (ni l'ancien hash, ni le nouveau mot de passe),
     * et retourne le mot de passe en clair pour un affichage unique côté admin.
     */
    public function reinitialiserMotDePasse(User $cible, User $auteur): string
    {
        $motDePasse = ProvisionnementMembreEquipe::genererMotDePasse();

        DB::transaction(function () use ($cible, $motDePasse, $auteur) {
            $cible->forceFill(['mot_de_passe_hash' => $motDePasse])->save();

            JournalAudit::create([
                'auteur_id' => $auteur->id,
                'role' => $auteur->role,
                'action' => 'Réinitialisation du mot de passe',
                'module' => 'Utilisateurs',
                'objet' => $cible->email,
                'resultat' => 'Succès',
            ]);
        });

        return $motDePasse;
    }

    /**
     * Corrige l'identité (prénom/nom/poste) d'un membre d'équipe (Lot 15a) —
     * un enregistrement RH que seul l'admin édite (jamais le membre lui-même :
     * la route appelante est `role:administrateur` strict, cf. docstring
     * {@see ModifierMembreRequest}). Aucun garde-fou
     * G1/G2 ici : l'identité ne touche pas à l'accès.
     *
     * @param  array{prenom: string, nom: string, poste: string}  $identite
     */
    public function modifierIdentite(User $cible, array $identite, User $auteur): void
    {
        $membre = $cible->membreEquipe;
        abort_if($membre === null, 404);

        $ancien = "prenom={$membre->prenom}, nom={$membre->nom}, poste={$membre->poste}";
        $nouveau = "prenom={$identite['prenom']}, nom={$identite['nom']}, poste={$identite['poste']}";

        if ($ancien === $nouveau) {
            return;
        }

        DB::transaction(function () use ($membre, $identite, $auteur, $cible, $ancien, $nouveau) {
            $membre->forceFill($identite)->save();

            JournalAudit::create([
                'auteur_id' => $auteur->id,
                'role' => $auteur->role,
                'action' => "Modification d'identité",
                'module' => 'Utilisateurs',
                'objet' => $cible->email,
                'ancienne_valeur' => $ancien,
                'nouvelle_valeur' => $nouveau,
                'resultat' => 'Succès',
            ]);
        });
    }
}
