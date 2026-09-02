<?php

namespace Tests\Feature\Admin;

use App\Models\Campagne;
use App\Models\Candidature;
use App\Models\EvaluationDossier;
use App\Models\Grille;
use App\Models\User;
use Database\Seeders\GrilleBaremeSeeder;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;

/**
 * Contexte des tests classement (Lot 5a) : fabrique de candidatures ÉVALUÉES
 * (dossier + entretien figés) avec attributs de départage maîtrisés, sans passer
 * par tout le parcours API (déjà testé aux Lots 3/4).
 */
trait CreeContexteClassement
{
    use CreeContexteEvaluation;

    private int $seqClassement = 0;

    protected function seedContexteClassement(): void
    {
        $this->seedReferentiels();
        $this->seed(GrilleBaremeSeeder::class);
    }

    /**
     * Candidature évaluée, prête pour le classement.
     *
     * @param  array{
     *   sexe?: string, se02?: string, se06?: string, se05?: string, mo04?: int,
     *   domaines?: list<string>, eligibilite?: string, date_soumission?: \DateTimeInterface,
     *   entretien_statut?: string
     * }  $opts
     */
    protected function candidatureEvaluee(
        Campagne $campagne,
        User $evaluateur,
        string $filiereCode,
        float $scoreDossier,
        float $scoreEntretien,
        array $opts = [],
    ): Candidature {
        $candidatUser = $this->creerCandidat(profil: ['sexe' => $opts['sexe'] ?? 'F']);
        $membreId = $evaluateur->membreEquipe->id;
        $grilleId = Grille::active()->id;

        $candidature = Candidature::create([
            'candidat_id' => $candidatUser->candidat->id,
            'campagne_id' => $campagne->id,
            'filiere_id' => $this->idFiliere($filiereCode),
            'numero_dossier' => sprintf('CASA-2026-%06d', ++$this->seqClassement),
            'cqp_confirme' => true,
        ]);

        $candidature->reponseFormulaire()->create([
            'se02_orphelin' => $opts['se02'] ?? 'non',
            'se06_soutien_menage' => $opts['se06'] ?? 'non',
            'se05_personnes_a_charge' => $opts['se05'] ?? '0',
        ]);
        $candidature->reponseFormulaire->forceFill(['mo04_note_etoiles' => $opts['mo04'] ?? 3])->save();

        foreach ($opts['domaines'] ?? [] as $domaine) {
            $candidature->experiences()->create(['domaine' => $domaine, 'duree_categorie' => 'moins_6']);
        }

        $candidature->forceFill([
            'evaluateur_id' => $membreId,
            'statut_interne' => 'evalue',
            'statut_eligibilite_interne' => $opts['eligibilite'] ?? 'eligible',
            'dossier_verrouille' => true,
            'dossier_verrouille_le' => now(),
            'dossier_verrouille_par' => $membreId,
            'date_soumission' => $opts['date_soumission'] ?? now()->subDays(15),
            'date_evaluation' => now()->subDays(3),
        ])->saveQuietly();

        EvaluationDossier::create([
            'candidature_id' => $candidature->id,
            'grille_id' => $grilleId,
            'score_total' => number_format($scoreDossier, 1, '.', ''),
            'valide' => true,
            'valide_le' => now(),
            'valide_par' => $membreId,
        ]);

        $entretienValide = ($opts['entretien_statut'] ?? 'valide') === 'valide';
        $candidature->entretien()->create([
            'statut' => $opts['entretien_statut'] ?? 'valide',
            'date' => '2026-07-06',
            'heure' => '09:00',
            'lieu' => 'Le Plateau',
            'evaluateur_id' => $membreId,
            'presence' => 'present',
            'grille_id' => $entretienValide ? $grilleId : null,
            'score_total' => $entretienValide ? number_format($scoreEntretien, 1, '.', '') : null,
            'valide_le' => $entretienValide ? now() : null,
            'valide_par' => $entretienValide ? $membreId : null,
        ]);

        return $candidature->fresh();
    }
}
