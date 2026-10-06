<?php

use App\Mail\PeerFingerprint;

// Épinglage de certificat SMTP (serveur à certificat AUTO-SIGNÉ, ex.
// mail.cci.ci) — calculé ici, PAS inline dans le tableau, pour n'évaluer
// env() qu'UNE fois et réutiliser le résultat pour les 2 clés ci-dessous.
// Normalisation dans App\Mail\PeerFingerprint (testée isolément, cf.
// PeerFingerprintTest) — accepte MAIL_PEER_FINGERPRINT avec ou sans ":",
// majuscule ou minuscule.
$mailPeerFingerprint = PeerFingerprint::normalize((string) env('MAIL_PEER_FINGERPRINT', ''));

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
            // Épinglage de certificat — clé `peer_fingerprint` reconnue
            // NATIVEMENT par Symfony\Mailer\Transport\Smtp\EsmtpTransportFactory
            // (lue sur le tableau d'options du DSN, cf.
            // MailManager::createSmtpTransport qui passe ce tableau tel quel) :
            // aucun code custom nécessaire, ces 2 clés de config suffisent.
            // `null` (MAIL_PEER_FINGERPRINT vide/absente) = comportement
            // standard INCHANGÉ (vérification de chaîne normale, comme
            // aujourd'hui).
            'peer_fingerprint' => $mailPeerFingerprint,
            // `verify_peer`/`verify_peer_name` désactivés UNIQUEMENT quand un
            // peer_fingerprint est réellement configuré. Nécessaire pour un
            // certificat AUTO-SIGNÉ (ex. mail.cci.ci) : aucune CA ne peut le
            // valider, la vérification de chaîne échouerait TOUJOURS, même
            // avec la bonne empreinte (testé en conditions réelles —
            // EsmtpTransportFactory applique les deux contrôles
            // indépendamment, cf. docs/DEPLOIEMENT.md). Ce n'est PAS un
            // affaiblissement : épingler l'empreinte EXACTE du certificat est
            // une garantie au moins aussi forte qu'une CA (un attaquant avec
            // un certificat valide pour un AUTRE hostname/CA serait quand
            // même rejeté, l'empreinte ne correspondrait pas). Sans variable
            // renseignée : clé absente, vérification standard inchangée.
            ...($mailPeerFingerprint === null ? [] : ['verify_peer' => false]),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

];
