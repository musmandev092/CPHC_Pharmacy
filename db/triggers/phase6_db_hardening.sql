-- CPHC Pharmacy — Phase 6 Postgres hardening.
--
-- Idempotent.  Re-applied on every scripts/deploy.sh run.
--
-- Locks the application role down with sensible runtime limits and removes
-- its ability to execute server-side file functions that would bypass our
-- defence-in-depth (a Postgres compromise should not be able to read
-- /etc/cphc-pharmacy/*.key, /var/lib/postgresql/data/postmaster.pid, etc).
--
-- NOT in this phase (tracked for Phase 7):
--   - Downgrading cphc_app from superuser to plain role.  That requires
--     introducing a separate cphc_admin superuser for bootstrap-only ops
--     (CREATE EXTENSION, ALTER ROLE), and rewiring seed-audit-key.sh and
--     deploy.sh to use it.  Doable but non-trivial; out of scope here.

-- ── 1. Per-session limits on the application role ──────────────────────────
-- statement_timeout: kills a single query after N ms.  Defends against
--   accidental Cartesian joins from buggy code and slow-loris query DoS.
--   30s is generous for our OLTP path (every sale path is < 50ms in practice).
-- idle_in_transaction_session_timeout: kills a transaction that's been left
--   open without progress.  Defends against connection-pool leaks holding
--   row locks and blocking other writers.
-- lock_timeout: bounds how long we'll wait for a row/table lock.  Combined
--   with the SERIALIZABLE isolation in SaleService, this prevents stuck
--   sale commits from blocking the next cashier indefinitely.
-- log_min_duration_statement: log any query slower than 1s.  Surface point
--   for performance regressions before they become an outage.
DO $$
DECLARE
    app_role TEXT;
BEGIN
    -- Use the role this script is connected as — set by .env's POSTGRES_USER.
    SELECT current_user INTO app_role;

    EXECUTE format('ALTER ROLE %I SET statement_timeout = %L',                       app_role, '30s');
    EXECUTE format('ALTER ROLE %I SET idle_in_transaction_session_timeout = %L',     app_role, '60s');
    EXECUTE format('ALTER ROLE %I SET lock_timeout = %L',                            app_role, '5s');
    EXECUTE format('ALTER ROLE %I SET log_min_duration_statement = %L',              app_role, '1s');

    RAISE NOTICE 'phase6: per-role timeouts applied to %', app_role;
END
$$;

-- ── 2. Revoke EXECUTE on server-side filesystem functions ──────────────────
-- These functions let a privileged session read host filesystem paths.
-- Even though our app user is currently superuser (Phase 7 will fix that),
-- revoking them from PUBLIC means a future role refactor inherits the lock
-- without extra work.  Wrapping in DO blocks so missing functions (older
-- Postgres versions) don't fail the script.
DO $$
DECLARE
    fn TEXT;
    candidates TEXT[] := ARRAY[
        'pg_read_server_files(text,bigint,bigint)',
        'pg_read_server_files(text)',
        'pg_read_binary_file(text)',
        'pg_read_binary_file(text,bigint,bigint)',
        'pg_read_binary_file(text,bigint,bigint,boolean)',
        'pg_write_server_files(text,bytea)',
        'pg_ls_dir(text)',
        'pg_ls_dir(text,boolean,boolean)',
        'pg_stat_file(text)',
        'pg_stat_file(text,boolean)'
    ];
BEGIN
    FOREACH fn IN ARRAY candidates LOOP
        BEGIN
            EXECUTE format('REVOKE EXECUTE ON FUNCTION %s FROM PUBLIC', fn);
        EXCEPTION
            WHEN undefined_function THEN
                -- Some functions don't exist on every Postgres version; skip.
                NULL;
        END;
    END LOOP;
    RAISE NOTICE 'phase6: revoked filesystem function execute from PUBLIC';
END
$$;

-- ── 3. Public schema lockdown reminder ──────────────────────────────────────
-- docker/postgres/init.sql already does `REVOKE CREATE ON SCHEMA public FROM
-- PUBLIC` at fresh-install time, but only the FIRST time a container starts
-- against an empty data dir.  Re-apply on every deploy so a manual GRANT
-- (or upgrade-time regression) gets undone.
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
