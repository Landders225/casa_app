<?php

namespace App\Notifications\Middleware;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Middleware de job de file (canal `mail` uniquement, cf.
 * App\Notifications\Concerns\EnvoiMailResilient::middleware()) — traite un
 * rejet SMTP 421/4xx (serveur temporairement indisponible/saturé, ex.
 * Exchange "Message submission rate... exceeded") comme TEMPORAIRE : le job
 * est remis en file avec un délai croissant, sans jamais tomber dans
 * `failed_jobs` tant que `retryUntil()` (cf. EnvoiMailResilient, +6h) n'est
 * pas écoulée. Un 5xx (adresse invalide, expéditeur refusé) reste un échec
 * DÉFINITIF — retenter ne changerait rien — on appelle `fail()` directement
 * plutôt que de laisser l'exception se propager (évite 2 tentatives inutiles
 * avant l'échec, cf. `--tries` du worker).
 *
 * Journalisation (Lot 18ter) : les DEUX cas (temporaire ET définitif) sont
 * loggés en `WARNING` — un 421/4xx est ATTENDU (pas une anomalie
 * applicative), mais reste un signal d'exploitation à ne pas noyer en
 * `info`. Jamais de niveau `error`/`critical` ici, et jamais l'objet
 * exception ni sa trace ne sont passés à `Log::` — uniquement 3 champs
 * scalaires (liste BLANCHE stricte : classe de notification, code SMTP,
 * numéro de tentative) ; ni l'adresse du destinataire, ni le sujet, ni le
 * contenu du message (testé, cf.
 * ToleranceSmtpTemporaireTest::test_le_journal_ne_contient_aucune_donnee_personnelle).
 * L'exception n'est jamais relancée (`throw`) pour ces 2 codes : elle ne
 * peut donc pas non plus déclencher un `error` avec trace complète via le
 * gestionnaire d'exceptions global du worker (vérifié en conditions
 * réelles — `storage/logs/laravel.log` ne contient QUE la ligne `WARNING`
 * ci-dessus pour un 421/550 simulé, cf. docs/DEPLOIEMENT.md §9.4bis).
 */
class ToleranceSmtpTemporaire
{
    private const DELAI_BASE_SECONDES = 30;

    private const DELAI_MAX_SECONDES = 600;

    public function handle(mixed $job, callable $next): void
    {
        try {
            $next($job);
        } catch (TransportExceptionInterface $e) {
            $code = $e->getCode();
            $tentative = $job->attempts();
            $contexte = [
                'notification' => get_class($job->notification),
                'code_smtp' => $code,
                'tentative' => $tentative,
            ];

            if ($code >= 500 && $code < 600) {
                Log::warning('Notification e-mail : échec SMTP définitif, job abandonné.', $contexte);
                $job->fail($e);

                return;
            }

            // 4xx (temporaire, ex. 421) OU code inconnu (coupure réseau, etc.)
            // — par prudence, traité comme temporaire plutôt qu'abandonné.
            $delai = (int) min(
                self::DELAI_BASE_SECONDES * (2 ** max($tentative - 1, 0)),
                self::DELAI_MAX_SECONDES
            );

            Log::warning('Notification e-mail : échec SMTP temporaire (421/4xx attendu), nouvelle tentative programmée.', [
                ...$contexte,
                'delai_secondes' => $delai,
            ]);

            $job->release($delai);
        }
    }
}
