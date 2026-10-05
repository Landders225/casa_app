<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Candidat\CreeContexteCandidature;
use Tests\TestCase;

/**
 * LIMITATION DE DÉBIT (Lot 10, T1 ; seuils ajustés pour les inscriptions/
 * connexions de GROUPE depuis une même IP publique — ajustement ultérieur).
 *
 * Avant le Lot 10, seuls `/login` et `/register` étaient limités : un compte
 * authentifié (ou un anonyme sur les routes publiques) pouvait marteler
 * n'importe quel endpoint. Limiteurs actuels :
 *   - `register`   40/min ET 600/j/IP — inscriptions (anti-bot)
 *   - `login`      5/min clé email+IP (anti-bruteforce PAR COMPTE, inchangé)
 *                  + 120/min par IP seule (anti-robot multi-comptes)
 *   - `casa-public` 600/min/IP  — /api/health, /api/filieres (hors session)
 *   - `casa-api`    120/min/user — filet global du groupe authentifié
 *   - `casa-uploads` 40/min/user — dépôt de pièces (finfo + écriture disque)
 *   - `casa-candidatures` 12/min/user — anti-spam de brouillons
 * `register`/`login`/`casa-public` sont lus depuis `config('casa.rate_limits.*')`
 * (config/casa.php, jamais `env()` direct — compatible `config:cache`) ; les
 * 3 autres restent des littéraux dans `AppServiceProvider` (inchangés, déjà
 * par UTILISATEUR donc hors sujet du partage d'IP).
 *
 * Calibrage : très au-dessus de l'usage humain le plus intense (le wizard fait
 * ~40 requêtes sur 10+ min). Ce test prouve à la fois que la limite EXISTE et
 * qu'un usage normal — y compris un groupe entier sur le même Wi-Fi — ne la
 * touche jamais.
 */
class RateLimitingTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    public function test_route_publique_limitee_a_600_par_minute(): void
    {
        config(['casa.rate_limits.public_per_minute' => 5]);

        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/health')->assertOk();
        }
        $this->getJson('/api/health')->assertStatus(429);
    }

    public function test_entete_de_limite_publique(): void
    {
        $this->getJson('/api/filieres')->assertHeader('X-RateLimit-Limit', 600);
    }

    public function test_filet_global_authentifie_annonce_120(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/me')->assertHeader('X-RateLimit-Limit', 120);
    }

    public function test_depot_de_piece_annonce_la_limite_uploads(): void
    {
        $this->seedReferentiels();
        $candidat = $this->creerCandidat();
        $candidatureId = $this->actingAs($candidat)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');

        // Le POST échoue en validation (pas de fichier) mais traverse le throttle :
        // c'est bien la limite `casa-uploads` (40) qui est annoncée, pas la globale.
        $this->actingAs($candidat)
            ->post("/api/candidatures/{$candidatureId}/pieces/cni", [])
            ->assertHeader('X-RateLimit-Limit', 40);
    }

    public function test_creation_de_candidature_limitee_a_12_par_minute(): void
    {
        $this->seedReferentiels();
        $candidat = $this->creerCandidat();
        $filiere = $this->idFiliere('cuisine');

        // Une seule candidature ouverte est permise fonctionnellement (409 ensuite),
        // mais le compteur de débit s'incrémente à CHAQUE tentative.
        for ($i = 0; $i < 12; $i++) {
            $this->actingAs($candidat)->postJson('/api/candidatures', ['filiere_id' => $filiere]);
        }
        $this->actingAs($candidat)->postJson('/api/candidatures', ['filiere_id' => $filiere])
            ->assertStatus(429);
    }

    public function test_un_usage_normal_du_wizard_ne_touche_jamais_la_limite(): void
    {
        $this->seedReferentiels();
        $candidat = $this->creerCandidat();
        $candidatureId = $this->actingAs($candidat)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');

        // 40 sauvegardes de réponses d'affilée (bien au-delà d'un vrai parcours
        // de 10 étapes) : toutes passent, la limite globale (120) n'est pas atteinte.
        for ($i = 0; $i < 40; $i++) {
            $this->actingAs($candidat)
                ->patchJson("/api/candidatures/{$candidatureId}/reponses", ['sc01_scolarise_actuellement' => 'non'])
                ->assertOk();
        }
    }

    public function test_login_reste_limite_independamment_du_filet_global(): void
    {
        User::factory()->create(['email' => 'x@exemple.ci']);

        // Preuve #2 : anti-bruteforce PAR COMPTE (clé email+IP) — 5 passent, le
        // 6e est bloqué. Inchangé par l'ajout du plafond IP seule ci-dessous
        // (chaque tentative incrémente aussi ce 2e compteur, mais 6 << 120).
        for ($i = 0; $i < 5; $i++) {
            $this->fromSpa()->postJson('/api/login', ['email' => 'x@exemple.ci', 'password' => 'faux'])
                ->assertStatus(422);
        }
        $this->fromSpa()->postJson('/api/login', ['email' => 'x@exemple.ci', 'password' => 'faux'])
            ->assertStatus(429);
    }

    /**
     * Génère un payload d'inscription valide avec e-mail/CNI distincts —
     * seul `email` a une contrainte unique en base.
     */
    private function payloadInscriptionGroupe(int $i): array
    {
        return [
            'email' => "groupe{$i}@exemple.ci",
            'password' => 'MotDePasse2026',
            'password_confirmation' => 'MotDePasse2026',
            'prenom' => 'Candidat',
            'nom' => "Groupe{$i}",
            'sexe' => 'F',
            'date_naissance' => '2001-05-14',
            'cni' => "CI{$i}0012345678",
            'telephone' => '0708091011',
            'ville_residence' => 'Abidjan - Yopougon',
            'residence_ci' => true,
            'cgu' => true,
        ];
    }

    /**
     * Preuve #1 (inscriptions de groupe, même IP publique) : 40 passent dans
     * la même minute (le seuil demandé), le 41e DANS LA MÊME minute est
     * bloqué, puis la minute suivante repart à zéro (fenêtre glissante, pas
     * un blocage permanent).
     */
    public function test_inscriptions_groupe_40_par_minute_puis_429_puis_reset_minute_suivante(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->fromSpa()->postJson('/api/register', $this->payloadInscriptionGroupe($i))
                ->assertCreated();
        }

        $this->fromSpa()->postJson('/api/register', $this->payloadInscriptionGroupe(40))
            ->assertStatus(429);

        $this->travel(61)->seconds();

        $this->fromSpa()->postJson('/api/register', $this->payloadInscriptionGroupe(41))
            ->assertCreated();
    }

    /**
     * Preuve #4 : le plafond JOURNALIER bloque bien au-delà de sa valeur,
     * sans envoyer 600 requêtes réelles — seuil abaissé à 3 via config() pour
     * ce test uniquement (même mécanisme que la variable d'environnement en
     * production, juste substitué en mémoire). Le seuil par minute (40, non
     * abaissé) ne peut pas interférer : seules 4 requêtes sont envoyées.
     */
    public function test_plafond_journalier_inscriptions_bloque_au_dela_du_seuil(): void
    {
        config(['casa.rate_limits.register_per_day' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->fromSpa()->postJson('/api/register', $this->payloadInscriptionGroupe($i))
                ->assertCreated();
        }

        $this->fromSpa()->postJson('/api/register', $this->payloadInscriptionGroupe(3))
            ->assertStatus(429);
    }

    /**
     * Preuve #3 : le NOUVEAU plafond par IP seule (120/min) n'empêche PAS un
     * groupe de 60 personnes avec 60 COMPTES DIFFÉRENTS de se connecter depuis
     * la même IP — chacune ne touche son propre compteur email+IP qu'une
     * fois (jamais les 5/min), et 60 < 120 pour le compteur IP partagé.
     */
    public function test_60_connexions_60_comptes_differents_meme_ip_passent_toutes(): void
    {
        $comptes = User::factory()->count(60)->create();

        foreach ($comptes as $compte) {
            $this->fromSpa()->postJson('/api/login', [
                'email' => $compte->email,
                'password' => 'password', // mot de passe par défaut de la factory
            ])->assertOk();
        }
    }

    /**
     * Complément à la preuve #3 : le plafond PARTAGÉ par IP bloque bien
     * au-delà de sa valeur — abaissé à 3 via config() pour ne pas dépendre
     * d'un vrai 120e compte (même mécanisme que le test du plafond journalier
     * ci-dessus).
     */
    public function test_plafond_ip_login_bloque_au_dela_de_sa_valeur(): void
    {
        config(['casa.rate_limits.login_ip_per_minute' => 3]);
        $comptes = User::factory()->count(4)->create();

        foreach ($comptes->take(3) as $compte) {
            $this->fromSpa()->postJson('/api/login', ['email' => $compte->email, 'password' => 'password'])
                ->assertOk();
        }

        $this->fromSpa()->postJson('/api/login', ['email' => $comptes->last()->email, 'password' => 'password'])
            ->assertStatus(429);
    }

    /**
     * Preuve #5 : les seuils sont bien lus depuis `config('casa.rate_limits.*')`
     * — si `AppServiceProvider` appelait `env()` directement (le piège signalé
     * avant d'écrire ce lot), muter `config()` à l'exécution n'aurait AUCUN
     * effet sur le comportement réel observé ici. C'est exactement ce que
     * `config:cache` fige au démarrage : la même valeur résolue, qu'elle
     * vienne du `.env` ou du cache.
     */
    public function test_les_seuils_sont_lus_depuis_config_pas_env_directement(): void
    {
        config(['casa.rate_limits.register_per_minute' => 2]);

        $this->fromSpa()->postJson('/api/register', $this->payloadInscriptionGroupe(900))->assertCreated();
        $this->fromSpa()->postJson('/api/register', $this->payloadInscriptionGroupe(901))->assertCreated();
        $this->fromSpa()->postJson('/api/register', $this->payloadInscriptionGroupe(902))
            ->assertStatus(429);
    }
}
