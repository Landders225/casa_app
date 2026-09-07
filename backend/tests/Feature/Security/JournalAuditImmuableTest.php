<?php

namespace Tests\Feature\Security;

use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Immuabilité RÉELLE du journal d'audit (ADR-12, règle 5) — Lot 10, T2.
 *
 * La migration `2026_09_02_100031_add_journal_audit_append_only_trigger`
 * installe un trigger PostgreSQL `BEFORE UPDATE OR DELETE` (par ligne) +
 * `BEFORE TRUNCATE` (par instruction) qui lève une exception. Jusqu'ici AUCUN
 * test ne le prouvait : un refactor de migration aurait pu le retirer en
 * silence. Ce test ferme ce trou.
 *
 * `INSERT` reste permis (sinon l'audit ne servirait à rien) ; toute autre
 * opération sur une ligne existante échoue — y compris par accès direct au
 * Query Builder, donc a fortiori par une route mal écrite.
 *
 * Note technique : chaque tentative interdite est enveloppée dans
 * `DB::transaction()` → sur la connexion déjà en transaction (RefreshDatabase),
 * cela pose un SAVEPOINT ; l'échec y est annulé et la transaction externe reste
 * utilisable pour les assertions qui suivent (sinon PostgreSQL refuserait toute
 * requête ultérieure — « current transaction is aborted »).
 */
class JournalAuditImmuableTest extends TestCase
{
    use RefreshDatabase;

    private function ligne(): JournalAudit
    {
        $auteur = User::factory()->administrateur()->create();

        return JournalAudit::create([
            'auteur_id' => $auteur->id,
            'role' => 'administrateur',
            'action' => 'Acte de test',
            'module' => 'Test',
            'objet' => 'dossier-x',
            'ancienne_valeur' => 'a',
            'nouvelle_valeur' => 'b',
            'resultat' => 'Succès',
        ]);
    }

    /** @return array{0:bool,1:string} [a levé une exception, message] */
    private function tenter(callable $op): array
    {
        try {
            DB::transaction($op);

            return [false, ''];
        } catch (QueryException $e) {
            return [true, $e->getMessage()];
        }
    }

    public function test_insert_est_permis(): void
    {
        $ligne = $this->ligne();
        $this->assertDatabaseHas('journal_audit', ['id' => $ligne->id, 'action' => 'Acte de test']);
    }

    public function test_update_d_une_ligne_existante_est_rejete(): void
    {
        $ligne = $this->ligne();

        [$aLeve, $message] = $this->tenter(
            fn () => DB::table('journal_audit')->where('id', $ligne->id)->update(['resultat' => 'Échec']),
        );

        $this->assertTrue($aLeve, 'Le trigger aurait dû rejeter le UPDATE.');
        $this->assertStringContainsString('append-only', $message);
        $this->assertSame('Succès', DB::table('journal_audit')->where('id', $ligne->id)->value('resultat'));
    }

    public function test_delete_d_une_ligne_existante_est_rejete(): void
    {
        $ligne = $this->ligne();

        [$aLeve, $message] = $this->tenter(
            fn () => DB::table('journal_audit')->where('id', $ligne->id)->delete(),
        );

        $this->assertTrue($aLeve, 'Le trigger aurait dû rejeter le DELETE.');
        $this->assertStringContainsString('append-only', $message);
        $this->assertDatabaseHas('journal_audit', ['id' => $ligne->id]);
    }

    public function test_truncate_est_rejete(): void
    {
        $this->ligne();

        [$aLeve, $message] = $this->tenter(fn () => DB::statement('TRUNCATE journal_audit'));

        $this->assertTrue($aLeve, 'Le trigger aurait dû rejeter le TRUNCATE.');
        $this->assertStringContainsString('append-only', $message);
        $this->assertGreaterThan(0, DB::table('journal_audit')->count());
    }

    public function test_le_modele_eloquent_ne_peut_pas_reecrire_une_ligne(): void
    {
        $ligne = $this->ligne();

        [$aLeve] = $this->tenter(fn () => $ligne->update(['nouvelle_valeur' => 'falsifié']));

        $this->assertTrue($aLeve);
        $this->assertSame('b', DB::table('journal_audit')->where('id', $ligne->id)->value('nouvelle_valeur'));
    }
}
