<?php

namespace App\Http\Controllers\Api;

use App\Domain\Eligibilite\ServiceEligibiliteInitiale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Candidat;
use App\Models\JournalAudit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * INSCRIPTION candidat (Lot 7, public) — comble ADR-13.
 *
 *   POST /api/register  -> 201 UserResource (+ session ouverte)
 *
 * Crée le COMPTE seul : `utilisateur` (role=candidat) + `candidat` (état civil).
 * La candidature reste un geste distinct (POST /api/candidatures, Lot 3a inchangé) :
 * la filière visée est un choix PAR candidature (re-candidature possible).
 *
 *  - éligibilité initiale (âge 18-30 + résidence CI) vérifiée AVANT toute écriture
 *    (ServiceEligibiliteInitiale, portage fidèle de scoring.js checkEligibiliteInitiale) ;
 *    échec -> 422 avec le(s) motif(s), aucun compte créé ;
 *  - `utilisateur` + `candidat` dans une seule transaction (jamais d'écriture partielle) ;
 *  - CGU horodatées (`cgu_acceptees_le`) — acte juridique tracé (Q2c) ;
 *  - auto-login (session régénérée) — pas de vérification d'e-mail en v1 (point ouvert) ;
 *  - `journal_audit` : « Inscription candidat ».
 */
class RegisterController extends Controller
{
    public function __construct(private readonly ServiceEligibiliteInitiale $eligibiliteInitiale)
    {
    }

    public function store(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $motifs = $this->eligibiliteInitiale->evaluer(
            CarbonImmutable::parse($data['date_naissance']),
            (bool) $data['residence_ci'],
        );
        abort_if($motifs !== [], 422, implode(' ', $motifs));

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'email' => $data['email'],
                'mot_de_passe_hash' => $data['password'], // cast 'hashed' -> bcrypt(12)
                'role' => 'candidat',
                'actif' => true,
                'cgu_acceptees_le' => now(),
                'derniere_connexion_le' => now(), // l'inscription ouvre une session
            ]);

            Candidat::create([
                'utilisateur_id' => $user->id,
                'prenom' => $data['prenom'],
                'nom' => $data['nom'],
                'sexe' => $data['sexe'],
                'date_naissance' => $data['date_naissance'],
                'cni' => $data['cni'],
                'telephone' => $data['telephone'],
                'ville_residence' => $data['ville_residence'],
                'residence_ci' => (bool) $data['residence_ci'],
            ]);

            JournalAudit::create([
                'auteur_id' => $user->id,
                'role' => 'candidat',
                'action' => 'Inscription candidat',
                'module' => 'Compte',
                'objet' => $user->email,
                'ancienne_valeur' => null,
                'nouvelle_valeur' => 'Compte candidat créé · CGU acceptées',
                'resultat' => 'Succès',
            ]);

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return (new UserResource($user->load('candidat', 'membreEquipe')))
            ->response()
            ->setStatusCode(201);
    }
}
