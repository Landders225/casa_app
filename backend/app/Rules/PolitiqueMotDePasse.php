<?php

namespace App\Rules;

use Illuminate\Validation\NotPwnedVerifier;
use Illuminate\Validation\Rules\Password;

/**
 * Politique de mot de passe CASA (ADR-16) — SOURCE UNIQUE des règles
 * `min:10 + minuscule + majuscule + chiffre + non-compromis (HIBP)`,
 * réutilisée par les 5 points d'entrée où un humain TAPE un mot de passe :
 * inscription (`Auth\RegisterRequest`), mot de passe oublié
 * (`Auth\ReinitialiserMotDePasseRequest`), changement connecté candidat/équipe
 * (`Candidat|Equipe\ChangerMotDePasseRequest`), création d'un compte équipe en
 * CLI (`ProvisionnementMembreEquipe::motDePasseRules()`, `casa:create-admin`/
 * `casa:create-membre`). Ne s'applique PAS au mot de passe PROVISOIRE généré
 * par l'admin (`ProvisionnementMembreEquipe::genererMotDePasse()`) : une
 * chaîne aléatoire n'a aucune raison d'être vérifiée contre une base de
 * fuites — juste un appel réseau superflu.
 *
 * `uncompromised()` (Lot 15d — Have I Been Pwned, ADR — Points ouverts R2).
 * K-anonymat NATIF Laravel : seul le préfixe SHA1 (5 caractères) part en
 * réseau, jamais le mot de passe en clair.
 *
 * FAIL-OPEN NATIF, pas recodé ici — {@see NotPwnedVerifier}
 * : une exception réseau (timeout, DNS, connexion refusée) OU une réponse
 * HTTP non-2xx sont TOUTES DEUX traitées comme « corps vide -> aucune
 * correspondance -> mot de passe accepté ». Si HIBP est injoignable,
 * l'inscription/le changement passe quand même — jamais bloqué par une panne
 * d'un service tiers. Le timeout est resserré à 3 s dans
 * `AppServiceProvider::register()` (30 s par défaut dans Laravel — trop long
 * pour ne pas geler perceptiblement l'écran si l'API répond lentement).
 */
final class PolitiqueMotDePasse
{
    public const MESSAGE_COMPROMIS = 'Ce mot de passe est trop répandu ou a déjà fuité dans une base de données compromises. Choisissez-en un autre.';

    public static function regles(): Password
    {
        return Password::min(10)->letters()->numbers()->mixedCase()->uncompromised();
    }
}
