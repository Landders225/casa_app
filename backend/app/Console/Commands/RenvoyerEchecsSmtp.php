<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `php artisan casa:renvoyer-echecs-smtp {--lot=} {--pause=60} {--dry-run}`
 * (Lot 18, ADR-35).
 *
 * ⚠️ `queue:retry all` NE DOIT PLUS être utilisée pour les notifications
 * e-mail : c'est littéralement ce qui a déclenché l'incident (141 échecs
 * d'un coup après un `queue:retry all`, Exchange a répondu 421 « Message
 * submission rate... exceeded »). Cette commande renvoie à la place par
 * PETITS LOTS ESPACÉS — défauts alignés sur `casa.mail_max_per_minute`
 * (`--lot`) et une minute (`--pause`), donc jamais plus rapide que le
 * limiteur de débit lui-même (`App\Notifications\Concerns\EnvoiMailResilient`).
 *
 * Ne touche QUE les lignes de `failed_jobs` dont la commande sérialisée est
 * une notification du canal `mail` (`Illuminate\Notifications\SendQueuedNotifications`
 * avec `mail` dans `channels`) — toute autre ligne (canal `database`, ou
 * illisible) est ignorée SANS y toucher, par prudence.
 *
 * Sans doublon : la liste des échecs ciblés est lue UNE SEULE FOIS au
 * démarrage ; `queue:retry` supprime chaque ligne de `failed_jobs` dès son
 * renvoi — une ligne déjà renvoyée ne peut donc pas être retraitée dans la
 * MÊME exécution, et une ré-exécution ultérieure ne voit plus que ce qui
 * reste (son propre `SELECT` initial, relu à chaque lancement).
 *
 * `--dry-run` : affiche le nombre et le détail des messages concernés, NE
 * RENVOIE RIEN.
 */
class RenvoyerEchecsSmtp extends Command
{
    protected $signature = 'casa:renvoyer-echecs-smtp
        {--lot= : Nombre de messages renvoyés par lot (défaut : casa.mail_max_per_minute)}
        {--pause=60 : Secondes de pause entre deux lots}
        {--dry-run : Affiche ce qui serait renvoyé, sans rien renvoyer}';

    protected $description = "Renvoie les notifications e-mail en échec (failed_jobs) par petits lots espacés — à utiliser à la place de 'queue:retry all'.";

    public function handle(): int
    {
        $lot = max(1, (int) ($this->option('lot') ?: config('casa.mail_max_per_minute')));
        $pause = max(0, (int) $this->option('pause'));
        $dryRun = (bool) $this->option('dry-run');

        $total = DB::table('failed_jobs')->count();
        $candidats = $this->echecsCanalMail();

        if ($candidats->isEmpty()) {
            $this->info("Aucun échec de notification e-mail à renvoyer (sur {$total} ligne(s) dans failed_jobs).");

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d échec(s) de notification e-mail trouvé(s) (sur %d ligne(s) dans failed_jobs au total — le reste, le cas échéant, est ignoré par cette commande).',
            $candidats->count(),
            $total
        ));

        if ($dryRun) {
            $this->table(
                ['notification', 'échoué le'],
                $candidats->map(fn ($f) => [$this->classeNotification($f) ?? '(illisible)', $f->failed_at])
            );
            $this->info('(--dry-run : rien n\'a été renvoyé.)');

            return self::SUCCESS;
        }

        $lots = $candidats->pluck('uuid')->chunk($lot)->values();
        $nombreLots = $lots->count();

        foreach ($lots as $index => $uuids) {
            $this->info(sprintf('Lot %d/%d : renvoi de %d message(s)...', $index + 1, $nombreLots, $uuids->count()));
            // `chunk()` PRÉSERVE les clés d'origine (`array_chunk(..., true)`) :
            // un lot au-delà du premier a des clés non ré-indexées à partir de
            // 0 (ex. `[6 => '...']`) — `queue:retry` plante alors sur
            // `$ids[0]` (`RetryCommand::getJobIds()`, qui suppose un tableau
            // 0-indexé). `values()` corrige ça juste avant l'appel.
            Artisan::call('queue:retry', ['id' => $uuids->values()->all()]);

            if ($index + 1 < $nombreLots) {
                $this->info("Pause de {$pause}s avant le lot suivant...");
                sleep($pause);
            }
        }

        $this->info('Terminé.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, object>
     */
    private function echecsCanalMail()
    {
        return DB::table('failed_jobs')->orderBy('id')->get()
            ->filter(fn ($f) => $this->estUneNotificationMail($f))
            ->values();
    }

    private function estUneNotificationMail(object $ligne): bool
    {
        $commande = $this->deserialiserCommande($ligne);

        return $commande instanceof SendQueuedNotifications
            && in_array('mail', $commande->channels ?? [], true);
    }

    private function classeNotification(object $ligne): ?string
    {
        $commande = $this->deserialiserCommande($ligne);

        return $commande instanceof SendQueuedNotifications ? get_class($commande->notification) : null;
    }

    private function deserialiserCommande(object $ligne): mixed
    {
        $payload = json_decode($ligne->payload, true);
        $serialise = $payload['data']['command'] ?? null;

        if (! is_string($serialise)) {
            return null;
        }

        try {
            $commande = @unserialize($serialise);
        } catch (Throwable) {
            return null;
        }

        return $commande === false ? null : $commande;
    }
}
