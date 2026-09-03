<?php

namespace App\Http\Controllers\Api\Candidat;

use App\Domain\Eligibilite\ServiceEligibiliteInitiale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Candidat\MettreAJourProfilRequest;
use App\Http\Resources\CandidatResource;
use App\Models\JournalAudit;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * PROFIL candidat (Lot 7) — état civil, propriétaire uniquement.
 *
 *   GET   /api/candidat/profil   -> CandidatResource (les 8 champs 🟢 de `candidat`)
 *   PATCH /api/candidat/profil   -> CandidatResource (après mise à jour)
 *
 * Pas de paramètre d'URL : l'endpoint agit TOUJOURS sur `request()->user()->candidat`.
 * Il est donc structurellement impossible de lire ou modifier le profil d'autrui
 * (mieux qu'un 404 : aucune surface).
 *
 *  - `email` / `residence_ci` / nationalité / diplôme : non éditables (cf. Request) ;
 *  - si `date_naissance` change, on relance ServiceEligibiliteInitiale avec le
 *    `residence_ci` ACTUEL : une correction qui ferait sortir de la tranche
 *    18-30 est refusée en 422 (cohérent avec le refus à l'inscription) ;
 *  - `journal_audit` : « Mise à jour du profil » + liste des champs modifiés.
 */
class ProfilController extends Controller
{
    public function __construct(private readonly ServiceEligibiliteInitiale $eligibiliteInitiale)
    {
    }

    public function show(Request $request): CandidatResource
    {
        $candidat = $request->user()->candidat;
        abort_if($candidat === null, 404, 'Aucun profil candidat pour ce compte.');

        return new CandidatResource($candidat);
    }

    public function update(MettreAJourProfilRequest $request): CandidatResource
    {
        $candidat = $request->user()->candidat;
        abort_if($candidat === null, 404, 'Aucun profil candidat pour ce compte.');

        $patch = $request->validated();

        if (array_key_exists('date_naissance', $patch)) {
            $motifs = $this->eligibiliteInitiale->evaluer(
                CarbonImmutable::parse($patch['date_naissance']),
                (bool) $candidat->residence_ci,
            );
            abort_if($motifs !== [], 422, implode(' ', $motifs));
        }

        $champsModifies = [];
        foreach ($patch as $champ => $valeur) {
            $ancien = $champ === 'date_naissance'
                ? $candidat->date_naissance?->toDateString()
                : $candidat->{$champ};
            if ((string) $ancien !== (string) $valeur) {
                $champsModifies[] = $champ;
            }
        }

        if ($champsModifies !== []) {
            $candidat->fill($patch)->save();

            JournalAudit::create([
                'auteur_id' => $request->user()->id,
                'role' => 'candidat',
                'action' => 'Mise à jour du profil',
                'module' => 'Compte',
                'objet' => $request->user()->email,
                'ancienne_valeur' => null,
                'nouvelle_valeur' => 'Champs modifiés : '.implode(', ', $champsModifies),
                'resultat' => 'Succès',
            ]);
        }

        return new CandidatResource($candidat->fresh());
    }
}
