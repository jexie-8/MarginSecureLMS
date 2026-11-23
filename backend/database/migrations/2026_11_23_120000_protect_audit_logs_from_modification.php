<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes the audit log append-only at the database level.
 *
 * The application has no routes that edit or delete audit records, but a bug
 * or a compromised application account could still try. Three layers stop it:
 *
 *  1. Privileges: the runtime role (margin_app) can only INSERT and SELECT.
 *  2. A trigger rejects UPDATE, DELETE and TRUNCATE for every role, including
 *     the table owner, except for the retention clean-up below and the foreign
 *     key's "clear actor_id" action if a user row is ever removed.
 *  3. Expired records are removed only by prune_audit_logs(), a SECURITY
 *     DEFINER function that refuses retention periods under 30 days, so the
 *     runtime role never needs DELETE rights.
 *
 * A database superuser can still disable the trigger on purpose. That is
 * unavoidable and is why the runtime account must never be a superuser.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION audit_logs_block_changes() RETURNS trigger
LANGUAGE plpgsql AS
$$
BEGIN
  -- Retention clean-up, only reachable through prune_audit_logs().
  IF TG_OP = 'DELETE' AND current_setting('margin.audit_prune', true) = 'on' THEN
    RETURN OLD;
  END IF;

  -- The foreign key on actor_id clears it if a user row is ever removed.
  -- Nothing else about the record may change.
  IF TG_OP = 'UPDATE'
     AND OLD.actor_id IS NOT NULL
     AND NEW.actor_id IS NULL
     AND (NEW.event, NEW.auditable_type, NEW.auditable_id, NEW.metadata,
          NEW.ip_address, NEW.user_agent, NEW.created_at)
         IS NOT DISTINCT FROM
         (OLD.event, OLD.auditable_type, OLD.auditable_id, OLD.metadata,
          OLD.ip_address, OLD.user_agent, OLD.created_at)
  THEN
    RETURN NEW;
  END IF;

  RAISE EXCEPTION 'audit_logs is append-only: % is not allowed', TG_OP
    USING ERRCODE = 'insufficient_privilege';
END
$$;

DROP TRIGGER IF EXISTS audit_logs_no_update_delete ON audit_logs;
CREATE TRIGGER audit_logs_no_update_delete
  BEFORE UPDATE OR DELETE ON audit_logs
  FOR EACH ROW EXECUTE FUNCTION audit_logs_block_changes();

DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs;
CREATE TRIGGER audit_logs_no_truncate
  BEFORE TRUNCATE ON audit_logs
  FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_block_changes();

CREATE OR REPLACE FUNCTION prune_audit_logs(p_retention_days integer) RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS
$$
DECLARE
  removed bigint;
BEGIN
  IF p_retention_days IS NULL OR p_retention_days < 30 THEN
    RAISE EXCEPTION 'Audit logs must be kept for at least 30 days (got %)', p_retention_days;
  END IF;

  PERFORM set_config('margin.audit_prune', 'on', true);
  DELETE FROM audit_logs WHERE created_at < now() - make_interval(days => p_retention_days);
  GET DIAGNOSTICS removed = ROW_COUNT;
  PERFORM set_config('margin.audit_prune', '', true);

  RETURN removed;
END
$$;

REVOKE ALL ON FUNCTION prune_audit_logs(integer) FROM PUBLIC;

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'margin_app') THEN
    REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM margin_app;
    GRANT EXECUTE ON FUNCTION prune_audit_logs(integer) TO margin_app;
  END IF;
END
$$;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs;
DROP TRIGGER IF EXISTS audit_logs_no_update_delete ON audit_logs;
DROP FUNCTION IF EXISTS prune_audit_logs(integer);
DROP FUNCTION IF EXISTS audit_logs_block_changes();
SQL);
    }
};
