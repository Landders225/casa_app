<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangerStatutCampagneRequest;
use App\Http\Requests\Admin\CreerCampagneRequest;
use App\Http\Requests\Admin\ModifierCampagneRequest;
use App\Http\Requests\Admin\ModifierQuotasRequest;
use App\Http\Resources\Admin\CampagneResource;
use App\Models\Campagne;
use App\Models\JournalAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * CAMPAGNES — liste (Lot 8d-1) + transitions d'état (Lot 6a) + création /
 * édition / quotas (Lot 17, D-6a-2) — administrateur strict.
 *
 *   GET   /api/admin/campagnes                    liste (avec filieres/quota, indicateurs)
 *   POST  /api/admin/campagnes                     créer (toujours en `brouillon`)
 *   PUT   /api/admin/campagnes/{campagne}           éditer nom/dates
 *   PUT   /api/admin/campagnes/{campagne}/quotas    éditer les quotas par filière
 *   PATCH /api/admin/campagnes/{campagne}           { statut: 'ouverte' | 'cloturee' }
 *
 * Transitions autorisées (PATCH) : `brouillon → ouverte`, `ouverte → cloturee`.
 * Toute autre → 422. `brouillon → ouverte` refusée (409) si une autre campagne
 * est déjà ouverte (règle « une seule campagne ouverte » du Lot 3a). Une
 * campagne `cloturee` n'accepte plus de candidature (`POST /api/candidatures`
 * → 409 « Aucune campagne ouverte », déjà en place). La CRÉATION ne touche
 * jamais `statut` (toujours `brouillon`, forcé serveur) : elle ne peut donc
 * jamais violer ce garde-fou (Étape 1, Q1).
 */
class CampagneController extends Controller
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'brouillon' => ['ouverte'],
        'ouverte' => ['cloturee'],
        'cloturee' => [],
    ];

    /**
     * Liste blanche (Lot 8d-1) — non paginée (peu de campagnes). Alimente
     * l'écran Campagnes (transitions) et l'écran Quotas (Lot 17, édition).
     * `withExists` : 2 sous-requêtes EXISTS corrélées, pas de N+1 (Étape 1 Q4).
     */
    public function index(): AnonymousResourceCollection
    {
        $campagnes = Campagne::query()
            ->withExists(['publication', 'decisionsCandidature'])
            ->with('filieres')
            ->orderByDesc('date_ouverture')
            ->get();

        return CampagneResource::collection($campagnes);
    }

    /**
     * Création (Lot 17) — TOUJOURS `brouillon` : le passage à `ouverte` reste
     * exclusivement `PATCH .../{id}` (garde-fou « une seule ouverte » déjà là,
     * pas dupliqué ici). `places_totales` est DÉRIVÉ (somme des quotas fournis),
     * jamais accepté depuis le client.
     */
    public function store(CreerCampagneRequest $request): JsonResponse
    {
        $data = $request->validated();

        $campagne = DB::transaction(function () use ($data, $request) {
            $campagne = Campagne::create([
                'nom' => $data['nom'],
                'statut' => 'brouillon',
                'date_ouverture' => $data['date_ouverture'],
                'date_cloture' => $data['date_cloture'],
                'places_totales' => collect($data['filieres'])->sum('quota'),
            ]);

            foreach ($data['filieres'] as $f) {
                $campagne->filieres()->attach($f['filiere_id'], ['quota' => (int) $f['quota']]);
            }

            JournalAudit::create([
                'auteur_id' => $request->user()->id,
                'role' => $request->user()->role,
                'action' => 'Création de campagne',
                'module' => 'Campagnes',
                'objet' => $campagne->nom,
                'ancienne_valeur' => null,
                'nouvelle_valeur' => sprintf(
                    'Brouillon, %d filière(s), %d places',
                    count($data['filieres']),
                    collect($data['filieres'])->sum('quota'),
                ),
                'resultat' => 'Succès',
            ]);

            return $campagne;
        });

        return $this->reponseCampagne($campagne, 201);
    }

    /**
     * Édition des informations générales — nom/dates (Lot 17). `statut` n'est
     * jamais ici (transitions = `changerStatut` ci-dessous, pas dupliqué).
     *
     * Étape 1, Q3 : une campagne `cloturee` a les DATES verrouillées (elles
     * ont un effet réel sur le processus — fenêtre officielle de la cohorte) ;
     * le NOM reste éditable (cosmétique, aucun effet sur le déroulé).
     */
    public function mettreAJour(ModifierCampagneRequest $request, Campagne $campagne): JsonResponse
    {
        $data = $request->validated();

        $datesModifiees = $campagne->date_ouverture->toDateString() !== $data['date_ouverture']
            || $campagne->date_cloture->toDateString() !== $data['date_cloture'];

        abort_if(
            $campagne->statut === 'cloturee' && $datesModifiees,
            409,
            'Cette campagne est clôturée : ses dates ne sont plus modifiables (le nom reste éditable).',
        );

        $ancien = ['nom' => $campagne->nom, 'date_ouverture' => $campagne->date_ouverture->toDateString(), 'date_cloture' => $campagne->date_cloture->toDateString()];

        $campagne->update([
            'nom' => $data['nom'],
            'date_ouverture' => $data['date_ouverture'],
            'date_cloture' => $data['date_cloture'],
        ]);

        if ($ancien['nom'] !== $campagne->nom || $datesModifiees) {
            JournalAudit::create([
                'auteur_id' => $request->user()->id,
                'role' => $request->user()->role,
                'action' => 'Modification de campagne',
                'module' => 'Campagnes',
                'objet' => $campagne->nom,
                'ancienne_valeur' => sprintf('%s (%s → %s)', $ancien['nom'], $ancien['date_ouverture'], $ancien['date_cloture']),
                'nouvelle_valeur' => sprintf('%s (%s → %s)', $campagne->nom, $data['date_ouverture'], $data['date_cloture']),
                'resultat' => 'Succès',
            ]);
        }

        return $this->reponseCampagne($campagne, 200);
    }

    /**
     * Édition des quotas par filière (Lot 17, ADR-09) — le point le plus
     * délicat du lot (Étape 1, Q2).
     *
     * Garde-fous, dans l'ordre :
     *  1. `publication` existe → 409, DUR. Au-delà de ce point, plus aucun
     *     recalcul n'est possible (`ClassementController::calculer()` se
     *     bloque déjà lui-même) : un quota qui changerait créerait un
     *     décalage PERMANENT avec des décisions déjà communiquées aux
     *     candidats. Aucune exception.
     *  2. Pas de publication, mais un classement était déjà calculé
     *     (`decision_candidature` non vide) → le quota change quand même
     *     (c'est un usage normal : ajuster puis recalculer), MAIS le
     *     classement existant est marqué `classement_perime = true`
     *     — STRUCTUREL, pas un warning que l'écran pourrait ignorer :
     *     `ClassementController::show()`/`etat()` reflète cet état, et
     *     `PublicationController::publier()` refuse tant qu'il est vrai.
     *     Seul un recalcul explicite (`POST .../classement`, inchangé) lève
     *     `classement_perime`.
     *  3. Aucun classement → édition libre, aucune trace de péremption.
     */
    public function modifierQuotas(ModifierQuotasRequest $request, Campagne $campagne): JsonResponse
    {
        abort_if(
            $campagne->publication()->exists(),
            409,
            'Les résultats de cette campagne sont publiés : les quotas ne sont plus modifiables.',
        );

        $campagne->loadMissing('filieres');
        $filieresParId = $campagne->filieres->keyBy('id');

        $avaitUnClassement = $campagne->decisionsCandidature()->exists();

        $changements = [];
        DB::transaction(function () use ($request, $campagne, $filieresParId, $avaitUnClassement, &$changements) {
            foreach ($request->validated('quotas') as $item) {
                $filiere = $filieresParId[$item['filiere_id']];
                $ancien = (int) $filiere->pivot->quota;
                $nouveau = (int) $item['quota'];

                if ($ancien !== $nouveau) {
                    $campagne->filieres()->updateExistingPivot($item['filiere_id'], ['quota' => $nouveau]);
                    $changements[] = sprintf('%s : %d → %d', $filiere->nom, $ancien, $nouveau);
                }
            }

            if ($changements === []) {
                return;
            }

            $campagne->load('filieres'); // relit les pivots à jour (pas `loadMissing`, déjà chargée)
            $campagne->places_totales = (int) $campagne->filieres->sum(fn ($f) => (int) $f->pivot->quota);
            $campagne->save();

            JournalAudit::create([
                'auteur_id' => $request->user()->id,
                'role' => $request->user()->role,
                'action' => 'Modification des quotas',
                'module' => 'Campagnes',
                'objet' => $campagne->nom,
                'ancienne_valeur' => null,
                'nouvelle_valeur' => implode(' ; ', $changements),
                'resultat' => 'Succès',
            ]);

            if ($avaitUnClassement && ! $campagne->classement_perime) {
                $campagne->forceFill(['classement_perime' => true])->save();

                JournalAudit::create([
                    'auteur_id' => $request->user()->id,
                    'role' => $request->user()->role,
                    'action' => 'Classement marqué périmé',
                    'module' => 'Classement',
                    'objet' => $campagne->nom,
                    'ancienne_valeur' => 'À jour',
                    'nouvelle_valeur' => 'Périmé (quotas modifiés) — recalcul requis',
                    'resultat' => 'Succès',
                ]);
            }
        });

        return $this->reponseCampagne($campagne, 200);
    }

    public function changerStatut(ChangerStatutCampagneRequest $request, Campagne $campagne): JsonResponse
    {
        $actuel = $campagne->statut;
        $cible = $request->validated('statut');

        abort_unless(
            in_array($cible, self::TRANSITIONS[$actuel] ?? [], true),
            422,
            "Transition non autorisée : « {$actuel} » → « {$cible} ».",
        );

        if ($cible === 'ouverte') {
            abort_if(
                Campagne::query()->where('statut', 'ouverte')->whereKeyNot($campagne->id)->exists(),
                409,
                "Une autre campagne est déjà ouverte : une seule campagne peut l'être à la fois.",
            );
        }

        $campagne->update(['statut' => $cible]);

        JournalAudit::create([
            'auteur_id' => $request->user()->id,
            'role' => $request->user()->role,
            'action' => $cible === 'ouverte' ? 'Ouverture de campagne' : 'Clôture de campagne',
            'module' => 'Campagnes',
            'objet' => $campagne->nom,
            'ancienne_valeur' => ucfirst($actuel),
            'nouvelle_valeur' => $cible === 'ouverte' ? 'Ouverte' : 'Clôturée',
            'resultat' => 'Succès',
        ]);

        return response()->json([
            'data' => [
                'id' => $campagne->id,
                'nom' => $campagne->nom,
                'statut' => $campagne->statut,
            ],
        ]);
    }

    /**
     * Réponse `CampagneResource` après une écriture (create/update) — recharge
     * `filieres` (pivots à jour) et les 2 indicateurs `withExists` de `index()`
     * (ici une seule campagne : deux requêtes EXISTS ciblées, pas de N+1 à
     * éviter sur un singleton).
     */
    private function reponseCampagne(Campagne $campagne, int $status): JsonResponse
    {
        $campagne->load('filieres');
        $campagne->setAttribute('publication_exists', $campagne->publication()->exists());
        $campagne->setAttribute('decisions_candidature_exists', $campagne->decisionsCandidature()->exists());

        return (new CampagneResource($campagne))
            ->response()
            ->setStatusCode($status);
    }
}
