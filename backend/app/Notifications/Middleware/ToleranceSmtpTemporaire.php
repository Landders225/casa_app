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
 * ⚠️ RGPD / vie privée — les champs journalisés sont une liste BLANCHE
 * stricte (classe de notification, code SMTP, numéro de tentative) : jamais
 * l'adresse du destinataire, le sujet ou le contenu du message (testé,
 * cf. ToleranceSmtpTemporaireTest::test_le_journal_ne_contient_aucune_donnee_personnelle).
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

            Log::info('Notification e-mail : échec SMTP temporaire, nouvelle tentative programmée.', [
                ...$contexte,
                'delai_secondes' => $delai,
            ]);

            $job->release($delai);
        }
    }
}
