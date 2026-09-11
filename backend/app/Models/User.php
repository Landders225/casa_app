<?php

namespace App\Models;

use App\Notifications\ReinitialisationMotDePasse;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * CASA — compte de connexion, table `utilisateur` (docs/mld.md §1, ADR-10).
 *
 * L'authentification Laravel est recâblée ici (pas de changement dans
 * config/auth.php) : `$table`, PK uuid, et surtout `getAuthPassword()` qui
 * pointe le guard sur `mot_de_passe_hash` (et non `password`).
 *
 * Sanctum SPA en session-cookie (ADR-01) : le « remember me » est neutralisé —
 * la table `utilisateur` n'a pas de colonne `remember_token` et une session
 * same-origin n'en a pas besoin.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    protected $table = 'utilisateur';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'mot_de_passe_hash',
        'role',
        'actif',
        'derniere_connexion_le',
        'cgu_acceptees_le',
    ];

    /**
     * Défense en profondeur — l'autorité de non-fuite reste l'API Resource
     * (liste blanche), cf. ADR-02.
     *
     * @var list<string>
     */
    protected $hidden = [
        'mot_de_passe_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'cree_le' => 'datetime',
            'derniere_connexion_le' => 'datetime',
            'cgu_acceptees_le' => 'datetime',
            'mot_de_passe_hash' => 'hashed',
        ];
    }

    /**
     * Le guard vérifie le mot de passe sur cette colonne (et non `password`).
     */
    public function getAuthPassword(): string
    {
        return $this->mot_de_passe_hash;
    }

    /**
     * Réinitialisation de mot de passe (Lot 13, ADR-32) — remplace la
     * notification `ResetPassword` par défaut de Laravel (qui pointe vers une
     * route web `password.reset` inexistante ici, API-only) par une
     * notification française, `ShouldQueue`, dont le lien cible la SPA.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ReinitialisationMotDePasse($token));
    }

    // --- « Remember me » neutralisé (pas de colonne, session SPA, ADR-01) ---

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // no-op
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    // --- Profils (sous-types exclusifs, ADR-10) ---

    /**
     * @return HasOne<Candidat, $this>
     */
    public function candidat(): HasOne
    {
        return $this->hasOne(Candidat::class, 'utilisateur_id');
    }

    /**
     * @return HasOne<MembreEquipe, $this>
     */
    public function membreEquipe(): HasOne
    {
        return $this->hasOne(MembreEquipe::class, 'utilisateur_id');
    }

    /**
     * Profil métier associé selon le rôle (candidat OU membre d'équipe).
     */
    public function profil(): ?object
    {
        return $this->isCandidat()
            ? $this->candidat
            : $this->membreEquipe;
    }

    // --- Rôles ---

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isCandidat(): bool
    {
        return $this->role === 'candidat';
    }

    public function isEvaluateur(): bool
    {
        return $this->role === 'evaluateur';
    }

    public function isAdministrateur(): bool
    {
        return $this->role === 'administrateur';
    }
}
