<?php

namespace App\Console\Commands;

use App\Models\Campagne;
use App\Models\JournalAudit;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `php artisan casa:rouvrir-campagne {campagne} --nouvelle-cloture=AAAA-MM-JJ
 *  --raison="…" --operateur=<email admin>` (ADR-43).
 *
 * EXCEPTION délibérée à l'irréversibilité de la clôture décrite côté
 * interface web (ADR-23 : « Clôturer étant irréversible » ; ADR-34 :
 * `TRANSITIONS['cloturee'] = []` dans `CampagneController::changerStatut`).
 * Cette commande écrit DIRECTEMENT via Eloquent (`$campagne->update()`), un
 * chemin que ce garde HTTP ne couvre pas et n'a jamais visé à couvrir (il
 * protège l'ÉCRAN admin contre une édition après coup, pas un canal serveur
 * plus privilégié). JAMAIS un bouton dans l'interface — volontaire.
 *
 * Garde-fous, DANS L'ORDRE (chacun bloque TOUTE écriture s'il échoue) :
 *  1. `--operateur` résout un compte administrateur RÉEL.
 *  2. `--raison` non vide.
 *  3. `--nouvelle-cloture` valide (AAAA-MM-JJ, date réellement existante)
 *     ET strictement future.
 *  4. Campagne trouvée ET statut EXACTEMENT 'cloturee'.
 *  5. Aucune AUTRE campagne 'ouverte' (même garde que `changerStatut()`).
 *  6. Confirmation interactive — AUCUN `--force` : jamais scriptable, exige
 *     un vrai terminal (`dca exec backend ...`, SANS `-T`).
 *
 * La nouvelle date de clôture reste PUREMENT INDICATIVE, comme à la création
 * (ADR-42) : rien ne re-clôture automatiquement à l'échéance — rappel
 * explicite affiché en fin de commande.
 */
class RouvrirCampagne extends Command
{
    protected $signature = 'casa:rouvrir-campagne
        {campagne : UUID de la campagne à rouvrir}
        {--nouvelle-cloture= : Nouvelle date de clôture (AAAA-MM-JJ), strictement future}
        {--raison= : Motif de la réouverture exceptionnelle (obligatoire)}
        {--operateur= : E-mail d\'un compte administrateur existant (résolu en auteur du journal_audit)}';

    protected $description = "Réouverture exceptionnelle d'une campagne clôturée — hors interface, journalisée (ADR-43)";

    public function handle(): int
    {
        $operateurEmail = $this->option('operateur');
        if (! is_string($operateurEmail) || $operateurEmail === '') {
            $this->error("--operateur est obligatoire (e-mail d'un compte administrateur).");

            return self::FAILURE;
        }

        $operateur = User::query()->where('email', $operateurEmail)->first();
        if ($operateur === null || ! $operateur->isAdministrateur()) {
            $this->error("Aucun compte administrateur trouvé pour l'e-mail « {$operateurEmail} ».");

            return self::FAILURE;
        }

        $raison = trim((string) $this->option('raison'));
        if ($raison === '') {
            $this->error('--raison est obligatoire (motif de la réouverture exceptionnelle).');

            return self::FAILURE;
        }

        $nouvelleClotureBrute = (string) $this->option('nouvelle-cloture');
        if ($nouvelleClotureBrute === '') {
            $this->error('--nouvelle-cloture est obligatoire (AAAA-MM-JJ).');

            return self::FAILURE;
        }

        try {
            $nouvelleCloture = Carbon::createFromFormat('Y-m-d', $nouvelleClotureBrute)->startOfDay();
        } catch (Exception) {
            $this->error("--nouvelle-cloture invalide (attendu AAAA-MM-JJ, reçu « {$nouvelleClotureBrute} »).");

            return self::FAILURE;
        }
        if ($nouvelleCloture->format('Y-m-d') !== $nouvelleClotureBrute) {
            // Carbon « corrige » silencieusement une date inexistante (ex. 2026-02-30 -> 2026-03-02) —
            // on refuse plutôt que d'accepter une date différente de celle tapée.
            $this->error("--nouvelle-cloture invalide (date inexistante : « {$nouvelleClotureBrute} »).");

            return self::FAILURE;
        }
        if (! $nouvelleCloture->isFuture()) {
            $this->error('--nouvelle-cloture doit être strictement future.');

            return self::FAILURE;
        }

        $campagneId = $this->argument('campagne');
        if (! Str::isUuid($campagneId)) {
            $this->error('UUID de campagne invalide.');

            return self::FAILURE;
        }

        $campagne = Campagne::find($campagneId);
        if ($campagne === null) {
            $this->error('Campagne introuvable.');

            return self::FAILURE;
        }
        if ($campagne->statut !== 'cloturee') {
            $this->error("Seule une campagne « cloturee » peut être rouverte par cette commande (statut actuel : « {$campagne->statut} »).");

            return self::FAILURE;
        }

        $autreOuverte = Campagne::query()->where('statut', 'ouverte')->whereKeyNot($campagne->id)->exists();
        if ($autreOuverte) {
            $this->error("Une autre campagne est déjà ouverte : une seule campagne peut l'être à la fois.");

            return self::FAILURE;
        }

        $ancienneCloture = $campagne->date_cloture->toDateString();
        $nouvelleClotureStr = $nouvelleCloture->toDateString();

        $this->warn("Vous allez ROUVRIR la campagne « {$campagne->nom} » (actuellement clôturée).");
        $this->line("Nouvelle date de clôture : {$nouvelleClotureStr} (ancienne : {$ancienneCloture}).");
        $this->line("Motif : {$raison}");
        $this->line("Opérateur : {$operateur->email}");

        if (! $this->confirm('Confirmer la réouverture exceptionnelle ?')) {
            $this->info('Annulé — aucune modification effectuée.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($campagne, $nouvelleClotureStr, $ancienneCloture, $raison, $operateur) {
            $campagne->update(['statut' => 'ouverte', 'date_cloture' => $nouvelleClotureStr]);

            JournalAudit::create([
                'auteur_id' => $operateur->id,
                'role' => $operateur->role,
                'action' => 'Réouverture exceptionnelle de campagne',
                'module' => 'Campagnes',
                'objet' => $campagne->nom,
                'ancienne_valeur' => "Clôturée (clôture : {$ancienneCloture})",
                'nouvelle_valeur' => "Ouverte (clôture : {$nouvelleClotureStr})",
                'motif' => $raison,
                'resultat' => 'Succès',
            ]);
        });

        $this->info("Campagne « {$campagne->nom} » rouverte avec succès.");
        $this->warn(
            'Rappel : la date de clôture est indicative — la campagne restera OUVERTE jusqu\'à une '
            .'clôture MANUELLE (bouton admin, ou une nouvelle exécution de cette commande), AUCUNE '
            .'fermeture automatique n\'aura lieu à l\'échéance.'
        );

        return self::SUCCESS;
    }
}
