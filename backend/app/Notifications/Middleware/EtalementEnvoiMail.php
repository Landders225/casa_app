<?php

namespace App\Notifications\Middleware;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Étale les envois SMTP à INTERVALLE RÉGULIER — un message toutes les
 * `60 / MAIL_MAX_PER_MINUTE` secondes, TOUTES notifications confondues
 * (`mail-critique` ET `mail-information` partagent la MÊME cadence : cf.
 * App\Notifications\Concerns\EnvoiMailResilient, le budget est unique).
 *
 * ⚠️ PAS un simple compteur par fenêtre de 60s
 * (`Illuminate\Queue\Middleware\RateLimited`, utilisé jusqu'au Lot 18bis) :
 * celui-ci laisse passer une RAFALE de N messages INSTANTANÉMENT en début de
 * fenêtre, puis bloque jusqu'à son terme. Un serveur sensible au débit
 * INSTANTANÉ (pas seulement au total par minute) rejette quand même une
 * rafale de ce genre. Ce middleware garantit un espacement RÉEL, mesuré et
 * prouvé sur un vrai serveur SMTP qui horodate (cf. docs/DEPLOIEMENT.md
 * §9.4bis, tests/Feature/Notifications/EtalementReelMailTest.php).
 *
 * Mécanisme : une clé de cache PARTAGÉE retient le PROCHAIN CRÉNEAU encore
 * disponible. Le PREMIER passage d'un job RÉSERVE ce créneau (ou
 * « maintenant » s'il est déjà passé) et avance la clé partagée de
 * `intervalle` secondes pour le job suivant ; ce créneau est alors mémorisé
 * SOUS LA CLÉ PROPRE À CE JOB (`notification->id`, stable d'une tentative à
 * l'autre), pour que les relances ultérieures le RELISENT au lieu d'en
 * réserver un NOUVEAU.
 *
 * ⚠️ Piège vérifié en conditions réelles (mesure Mailpit horodatée,
 * cf. docs/DEPLOIEMENT.md §9.4bis) : une PROPRIÉTÉ D'INSTANCE mutée en
 * mémoire NE SURVIT PAS à un `release()` — `Illuminate\Queue\DatabaseQueue
 * ::release()` repousse le PAYLOAD BRUT D'ORIGINE (`$job->payload`, les
 * octets sérialisés AU PREMIER dispatch), jamais une ré-sérialisation de
 * l'objet en mémoire après mutation. Une première version de ce middleware
 * mémorisait le créneau sur `$this` (propriété d'instance) : chaque relance
 * repartait donc de zéro et RÉSERVAIT UN NOUVEAU créneau à chaque tentative,
 * repoussant la cadence de tous les jobs suivants à l'infini (observé :
 * aucun espacement réel, un seul message livré sur 4 en 28 s réelles). D'où
 * la clé de cache EXTERNE (pas une propriété PHP) comme SEULE source de
 * vérité pour la mémorisation entre tentatives.
 *
 * Verrou atomique (`Cache::lock`, supporté par le store `database`, cf.
 * `config/cache.php` `lock_table`) pour rester correct même si plusieurs
 * workers tournaient un jour en parallèle. Un job dont le créneau n'est pas
 * encore là est remis en file (`release()`) jusqu'à son heure — jamais
 * d'attente active (`sleep()`) dans le worker.
 */
class EtalementEnvoiMail
{
    private const CLE_PROCHAIN_CRENEAU = 'casa:mail:prochain-creneau';

    private const CLE_VERROU = 'casa:mail:verrou-cadence';

    private const TTL_PROCHAIN_CRENEAU_SECONDES = 3600;

    private const TTL_CRENEAU_PAR_JOB_SECONDES = 86400;

    public function handle(mixed $job, callable $next): mixed
    {
        $cleJob = $this->cleCreneauJob($job);

        $creneau = Cache::get($cleJob) ?? $this->reserverCreneau($cleJob);

        $delai = max(0, now()->diffInSeconds(Carbon::parse($creneau), false));

        if ($delai > 0) {
            $job->release($delai);

            return null;
        }

        return $next($job);
    }

    private function cleCreneauJob(mixed $job): string
    {
        return 'casa:mail:creneau-job:'.$job->notification->id;
    }

    private function reserverCreneau(string $cleJob): string
    {
        $intervalle = 60 / max(1, (int) config('casa.mail_max_per_minute'));

        return Cache::lock(self::CLE_VERROU, 10)->block(5, function () use ($intervalle, $cleJob) {
            // Entre le `Cache::get` hors-verrou (ci-dessus) et l'acquisition
            // du verrou, une tentative CONCURRENTE a pu réserver pour CE
            // MÊME job (deux workers, improbable mais possible) : relire
            // sous le verrou avant de réserver pour de bon.
            $existant = Cache::get($cleJob);
            if ($existant !== null) {
                return $existant;
            }

            $maintenant = Carbon::now();
            $memorise = Cache::get(self::CLE_PROCHAIN_CRENEAU);
            $prochainDisponible = $memorise ? Carbon::parse($memorise) : $maintenant;

            $creneauReserve = $prochainDisponible->greaterThan($maintenant) ? $prochainDisponible : $maintenant;

            Cache::put(
                self::CLE_PROCHAIN_CRENEAU,
                $creneauReserve->copy()->addSeconds($intervalle)->toIso8601String(),
                self::TTL_PROCHAIN_CRENEAU_SECONDES
            );
            Cache::put($cleJob, $creneauReserve->toIso8601String(), self::TTL_CRENEAU_PAR_JOB_SECONDES);

            return $creneauReserve->toIso8601String();
        });
    }
}
