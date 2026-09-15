<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug trouvé en conditions réelles (2026-09-15) : sans
 * `backend/lang/fr/validation.php`, TOUTE règle de validation sans message
 * personnalisé explicite dans un `FormRequest::messages()` affichait sa clé
 * technique brute (ex. « validation.min.string ») au lieu d'un texte lisible
 * — pas seulement `Password::min()`, observé aussi sur `required` (11 champs
 * d'un coup à l'inscription). Laravel 12 ne fournit plus de fichiers de
 * langue par défaut ; sans ce fichier ET sans message personnalisé, le
 * traducteur affiche la clé telle quelle.
 *
 * Garde-fou GÉNÉRAL, pas juste `min` : `assertMessageLisible()` vérifie
 * l'ABSENCE de la sous-chaîne « validation. » n'importe où dans le corps de
 * la réponse — une règle ajoutée demain sans message personnalisé (ex.
 * `alpha`, `url`, `between`) reste couverte par le même filet, pas seulement
 * celles explicitement exercées ici.
 */
class TraductionValidationTest extends TestCase
{
    use RefreshDatabase;

    private function assertMessageLisible(string $corpsJson): void
    {
        $this->assertStringNotContainsString(
            'validation.',
            $corpsJson,
            'Clé de traduction BRUTE détectée dans la réponse — un message n\'est pas traduit (backend/lang/fr/validation.php à compléter).',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function inscriptionValide(array $overrides = []): array
    {
        return array_merge([
            'email' => 'traduction@example.ci', 'password' => 'MotDePasse2026', 'password_confirmation' => 'MotDePasse2026',
            'prenom' => 'T', 'nom' => 'T', 'sexe' => 'F', 'date_naissance' => '2001-01-01',
            'cni' => 'CI1', 'telephone' => '0700000000', 'ville_residence' => 'Abidjan',
            'residence_ci' => true, 'cgu' => true,
        ], $overrides);
    }

    public function test_mot_de_passe_trop_court_est_en_francais_lisible(): void
    {
        $reponse = $this->fromSpa()->postJson('/api/register', $this->inscriptionValide([
            'password' => 'Ab1', 'password_confirmation' => 'Ab1',
        ]))->assertStatus(422);

        $this->assertMessageLisible($reponse->getContent());
        $this->assertSame('Le champ mot de passe doit contenir au moins 10 caractères.', $reponse->json('errors.password.0'));
    }

    public function test_champs_obligatoires_manquants_sont_en_francais_lisible(): void
    {
        $reponse = $this->fromSpa()->postJson('/api/register', [])->assertStatus(422);

        $this->assertMessageLisible($reponse->getContent());
        $this->assertSame('Le champ adresse e-mail est obligatoire.', $reponse->json('errors.email.0'));
        $this->assertSame('Le champ prénom est obligatoire.', $reponse->json('errors.prenom.0'));
        $this->assertSame('Le champ mot de passe est obligatoire.', $reponse->json('errors.password.0'));
    }

    /**
     * `sexe` utilise `Rule::in(['F','H'])` — AUCUN message personnalisé
     * (`RegisterRequest::messages()` ne le couvre pas) : preuve que le
     * garde-fou joue aussi sur une règle qui n'est ni `min` ni `required`.
     */
    public function test_valeur_hors_enumeration_est_en_francais_lisible(): void
    {
        $reponse = $this->fromSpa()->postJson('/api/register', $this->inscriptionValide(['sexe' => 'X']))
            ->assertStatus(422);

        $this->assertMessageLisible($reponse->getContent());
        $this->assertStringNotContainsString('validation.', (string) $reponse->json('errors.sexe.0'));
    }
}
