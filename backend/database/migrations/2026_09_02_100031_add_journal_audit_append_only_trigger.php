<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CASA — Immuabilité réelle du journal d'audit au niveau PostgreSQL (ADR-12).
 *
 * Un trigger `BEFORE UPDATE OR DELETE` (par ligne) + `BEFORE TRUNCATE` (par
 * instruction) sur `journal_audit` lève une exception et rejette toute tentative
 * de modification/suppression d'une ligne existante — y compris par un accès
 * direct à la base ou une future route mal écrite.
 *
 * Pourquoi un trigger plutôt qu'un REVOKE de privilèges : le trigger protège
 * indépendamment du rôle SQL utilisé, y compris le propriétaire des tables dont
 * se sert Laravel (qu'un REVOKE UPDATE/DELETE n'atteindrait pas).
 *
 * Seul `INSERT` reste permis. Le `DROP TABLE` de `migrate:fresh` n'est pas
 * concerné (DDL de gestion de schéma, pas une altération de ligne).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION casa_journal_audit_append_only()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'journal_audit est append-only : operation % interdite (regle 5 / ADR-12)', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE TRIGGER trg_journal_audit_no_update_delete
                BEFORE UPDATE OR DELETE ON journal_audit
                FOR EACH ROW EXECUTE FUNCTION casa_journal_audit_append_only();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE TRIGGER trg_journal_audit_no_truncate
                BEFORE TRUNCATE ON journal_audit
                FOR EACH STATEMENT EXECUTE FUNCTION casa_journal_audit_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_journal_audit_no_truncate ON journal_audit');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_journal_audit_no_update_delete ON journal_audit');
        DB::unprepared('DROP FUNCTION IF EXISTS casa_journal_audit_append_only()');
    }
};
