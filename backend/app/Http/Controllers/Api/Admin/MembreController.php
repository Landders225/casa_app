<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EnregistrerMembreRequest;
use App\Http\Requests\Admin\ModifierMembreRequest;
use App\Http\Resources\Admin\MembreResource;
use App\Models\User;
use App\Services\GestionCompteEquipe;
use App\Services\ProvisionnementMembreEquipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * COMPTES DE L'ÉQUIPE — gestion (Lot 11b, ADR-29) — administrateur strict.
 *
 *   GET   /api/admin/membres                          liste (évaluateurs + admins) + charge
 *   POST  /api/admin/membres                          création (rôle validé, mot de passe généré)
 *   PATCH /api/admin/membres/{utilisateur}            { actif } et/ou { prenom, nom, poste } (Lot 15a)
 *   POST  /api/admin/membres/{utilisateur}/mot-de-passe   réinitialisation (mot de passe généré)
 *
 * C'est l'ouverture de l'autorisation EN ÉCRITURE : chaque acte est tracé
 * (`journal_audit`, auteur = l'admin connecté), aucune réponse n'expose de hash
 * ni de mot de passe au repos, et `{utilisateur}` qui ne désigne pas un membre
 * d'équipe (un candidat, un id inconnu) → 404 (on ne révèle rien).
 */
class MembreController extends Controller
{
    /**
     * Liste non paginée (équipe de taille réduite, cf. `GET /admin/evaluateurs`).
     * Charge par membre : dossiers affectés + dossiers évalués, dérivés de
     * `membre_equipe.dossiersAffectes` (`evaluateur_id`).
     */
    public function index(): AnonymousResourceCollection
    {
        $membres = User::query()
            ->whereIn('role', ProvisionnementMembreEquipe::ROLES)
            ->with(['membreEquipe' => fn ($q) => $q->withCount([
                'dossiersAffectes as dossiers_affectes_count',
                'dossiersAffectes as dossiers_evalues_count' => fn ($q) => $q->where('statut_interne', 'evalue'),
            ])])
            ->get()
            ->sortBy(fn (User $u) => mb_strtolower(($u->membreEquipe?->nom ?? '').' '.($u->membreEquipe?->prenom ?? '')))
            ->values();

        return MembreResource::collection($membres);
    }

    /**
     * Réutilise la logique de `casa:create-membre` (Lot 11a) via
     * {@see ProvisionnementMembreEquipe} — mais l'auteur de l'audit est l'admin
     * connecté (pas d'auto-provisionnement ici). Le mot de passe temporaire
     * généré est renvoyé UNE SEULE FOIS.
     */
    public function store(EnregistrerMembreRequest $request, ProvisionnementMembreEquipe $provisionnement): JsonResponse
    {
        $motDePasse = ProvisionnementMembreEquipe::genererMotDePasse();

        $user = $provisionnement->creer([
            'email' => $request->validated('email'),
            'role' => $request->validated('role'),
            'password' => $motDePasse,
            'prenom' => $request->validated('prenom'),
            'nom' => $request->validated('nom'),
            'poste' => $request->validated('poste'),
        ], $request->user());

        return response()->json([
            'data' => new MembreResource($user->load('membreEquipe')),
            'mot_de_passe_temporaire' => $motDePasse,
        ], 201);
    }

    /**
     * Deux actions indépendantes, jamais combinées par l'UI (Étape 1, Q3) mais
     * acceptées séparément ou ensemble par le serveur : `actif` (garde-fous
     * G1/G2) et/ou `prenom`/`nom`/`poste` (identité, Lot 15a — RH, admin seul).
     */
    public function modifier(ModifierMembreRequest $request, User $utilisateur, GestionCompteEquipe $gestion): MembreResource
    {
        $this->assertMembreEquipe($utilisateur);
        $validated = $request->validated();

        if (array_key_exists('actif', $validated)) {
            $gestion->definirActivation($utilisateur, (bool) $validated['actif'], $request->user());
        }

        if (array_key_exists('prenom', $validated)) {
            $gestion->modifierIdentite($utilisateur, [
                'prenom' => $validated['prenom'],
                'nom' => $validated['nom'],
                'poste' => $validated['poste'],
            ], $request->user());
        }

        return new MembreResource($this->rechargerAvecCharge($utilisateur));
    }

    /**
     * Réponse : `{ mot_de_passe_temporaire }` — le NOUVEAU mot de passe, une
     * seule fois. Jamais l'ancien hash, jamais de valeur dans l'audit.
     */
    public function reinitialiserMotDePasse(Request $request, User $utilisateur, GestionCompteEquipe $gestion): JsonResponse
    {
        $this->assertMembreEquipe($utilisateur);

        $motDePasse = $gestion->reinitialiserMotDePasse($utilisateur, $request->user());

        return response()->json(['mot_de_passe_temporaire' => $motDePasse]);
    }

    /**
     * `{utilisateur}` doit être un membre d'équipe (évaluateur ou admin). Un
     * candidat, un id inconnu → 404, indiscernables (pas d'oracle).
     */
    private function assertMembreEquipe(User $utilisateur): void
    {
        abort_unless(in_array($utilisateur->role, ProvisionnementMembreEquipe::ROLES, true), 404);
    }

    private function rechargerAvecCharge(User $utilisateur): User
    {
        return $utilisateur->load(['membreEquipe' => fn ($q) => $q->withCount([
            'dossiersAffectes as dossiers_affectes_count',
            'dossiersAffectes as dossiers_evalues_count' => fn ($q) => $q->where('statut_interne', 'evalue'),
        ])]);
    }
}
