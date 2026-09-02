<?php

namespace Tests\Feature\Evaluateur;

use App\Models\Candidature;
use App\Models\EvaluationDossier;
use App\Models\Grille;
use App\Models\MembreEquipe;
use App\Models\User;
use Database\Seeders\GrilleBaremeSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Candidat\CreeContexteCandidature;

/**
 * Contexte des tests évaluateur (Lot 4a). Réutilise le contexte candidat
 * (référentiels, `creerCandidat`, fichiers) et ajoute la fabrique de comptes
 * équipe + l'affectation (aucun endpoint d'affectation n'existe — c'est une
 * action admin d'un lot ultérieur, cf. D-4a-1).
 */
trait CreeContexteEvaluation
{
    use CreeContexteCandidature;

    protected function creerMembreEquipe(string $role, ?string $email = null): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'email' => $email ?? fake()->unique()->safeEmail(),
        ]);

        MembreEquipe::create([
            'utilisateur_id' => $user->id,
            'prenom' => $role === 'administrateur' ? 'Admin' : 'Eval',
            'nom' => 'Test',
            'poste' => $role === 'administrateur' ? 'Coordination CASA' : "Chargé d'évaluation",
        ]);

        return $user->fresh();
    }

    protected function creerEvaluateur(?string $email = null): User
    {
        return $this->creerMembreEquipe('evaluateur', $email);
    }

    protected function creerAdmin(?string $email = null): User
    {
        return $this->creerMembreEquipe('administrateur', $email);
    }

    /** Barème complet en base (grille v1 active) — requis pour le scoring (Lot 4b). */
    protected function seedBareme(): void
    {
        $this->seed(GrilleBaremeSeeder::class);
    }

    /**
     * Pose (ou met à jour) la vérification 4a d'un dossier — préalable à la
     * validation de la notation (Lot 4b).
     */
    protected function poserVerification(Candidature $candidature, ?string $diplome = 'bac', ?bool $nationalite = true): void
    {
        $verification = $candidature->verification()->firstOrNew([]);
        $verification->forceFill([
            'diplome_verifie' => $diplome,
            'nationalite_confirmee' => $nationalite,
            'verifie_le' => now(),
        ])->save();
        $candidature->refresh();
    }

    /**
     * Simule l'affectation admin : evaluateur_id + statut_interne='en_instruction'.
     * `$reponses` permet de fixer des réponses (pour les critères d'éligibilité).
     *
     * @param  array<string, mixed>  $reponses
     */
    protected function affecter(Candidature $candidature, User $evaluateur, array $reponses = []): Candidature
    {
        $candidature->reponseFormulaire->fill(array_merge($this->reponsesEligibles(), $reponses))->save();

        $candidature->forceFill([
            'evaluateur_id' => $evaluateur->membreEquipe->id,
            'statut_interne' => 'en_instruction',
            'statut_eligibilite_interne' => 'eligible',
            'date_soumission' => now()->subDay(),
        ])->saveQuietly();

        return $candidature->fresh();
    }

    /**
     * Candidature soumise du candidat `$candidat`, affectée à `$evaluateur`.
     *
     * @param  array<string, mixed>  $reponses
     */
    protected function candidatureAffectee(User $candidat, User $evaluateur, array $reponses = []): Candidature
    {
        $id = $this->actingAs($candidat)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');

        return $this->affecter(Candidature::findOrFail($id), $evaluateur, $reponses);
    }

    /**
     * Verrouille le dossier /65 (état post-Lot 4b) — préalable à l'entretien (4c).
     */
    protected function verrouillerDossier(Candidature $candidature, string $scoreTotal = '45.0'): Candidature
    {
        EvaluationDossier::create([
            'candidature_id' => $candidature->id,
            'grille_id' => Grille::active()->id,
            'score_total' => $scoreTotal,
            'valide' => true,
            'valide_le' => now(),
            'valide_par' => $candidature->evaluateur_id,
        ]);

        $candidature->forceFill([
            'dossier_verrouille' => true,
            'dossier_verrouille_le' => now(),
            'dossier_verrouille_par' => $candidature->evaluateur_id,
            'statut_interne' => 'evalue',
            'date_evaluation' => now()->toDateString(),
        ])->saveQuietly();

        return $candidature->fresh();
    }

    /**
     * Clone la grille active en une nouvelle version active (poids de chaque
     * rubrique + 1, sous-critères et items inclus). Prouve le non-recalcul des
     * snapshots (ADR-04).
     */
    protected function activerGrilleClone(int $version = 2): Grille
    {
        $source = Grille::active()->load('volets.rubriques.items.options', 'volets.rubriques.sousCriteres');
        DB::table('grille')->where('id', $source->id)->update(['actif' => false]);

        $clone = Grille::create([
            'version' => $version,
            'label' => "Grille v{$version} (clone de test)",
            'date_effet' => now()->toDateString(),
            'actif' => true,
            'created_at' => now(),
        ]);

        foreach ($source->volets as $volet) {
            $nvVolet = $clone->volets()->create([
                'code' => $volet->code, 'label' => $volet->label, 'max_points' => $volet->max_points,
            ]);

            foreach ($volet->rubriques as $rubrique) {
                $nvRubrique = $nvVolet->rubriques()->create([
                    'code' => $rubrique->code, 'label' => $rubrique->label,
                    'max_points' => (float) $rubrique->max_points + 1, 'ordre' => $rubrique->ordre,
                ]);

                foreach ($rubrique->items as $item) {
                    $nvItem = $nvRubrique->items()->create([
                        'code' => $item->code, 'label' => $item->label, 'type' => $item->type,
                        'max_points' => $item->max_points, 'notation_evaluateur' => $item->notation_evaluateur,
                        'notee' => $item->notee, 'eliminatoire' => $item->eliminatoire,
                        'eliminatoire_groupe' => $item->eliminatoire_groupe,
                    ]);
                    foreach ($item->options as $option) {
                        $nvItem->options()->create([
                            'valeur' => $option->valeur, 'label' => $option->label,
                            'points' => $option->points, 'eliminatoire' => $option->eliminatoire,
                        ]);
                    }
                }

                foreach ($rubrique->sousCriteres as $sousCritere) {
                    $nvRubrique->sousCriteres()->create([
                        'code' => $sousCritere->code, 'label' => $sousCritere->label,
                        'max_points' => (float) $sousCritere->max_points + 1,
                    ]);
                }
            }
        }

        return $clone->fresh();
    }
}
