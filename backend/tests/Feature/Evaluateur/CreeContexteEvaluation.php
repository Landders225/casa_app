<?php

namespace Tests\Feature\Evaluateur;

use App\Models\Candidature;
use App\Models\MembreEquipe;
use App\Models\User;
use Database\Seeders\GrilleBaremeSeeder;
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
}
