-- CPHC Pharmacy — audit_log integrity (Phase 3 hardening).
--
-- This script enforces TWO independent guarantees on the audit_log table:
--
--   1) APPEND-ONLY:    UPDATE and DELETE are rejected unconditionally.
--      Even Postgres superuser sees the exception (the previous version
--      was bypass-able by DROP TRIGGER as superuser; the chain below
--      adds cryptographic tamper-evidence on top, so dropping the trigger
--      and forging history still fails verification).
--
--   2) HMAC CHAIN:     Every INSERT computes a row_hmac =
--                      hex(HMAC_SHA256(secret, prev_hmac || canonical_payload))
--      where prev_hmac is the row_hmac of the immediately preceding chain
--      row (the highest id with non-empty row_hmac).  An attacker who
--      modifies a row's data, OR rewrites a row_hmac, OR drops + reinserts
--      a row, breaks the chain at that row and forward.  Without the
--      external secret they cannot recompute valid hmacs to "heal" the
--      chain.
--
-- The secret is read from the per-role custom GUC `cphc.audit_secret`,
-- seeded once at deploy time by scripts/seed-audit-key.sh from the host
-- file /etc/cphc-pharmacy/audit.key (root:root 0600).
--
-- Idempotent: safe to re-run.

-- ── 0. Schema migration: add chain columns if upgrading from pre-Phase-3 ──
-- IF NOT EXISTS lets this file double as a fresh-install schema (called from
-- /docker-entrypoint-initdb.d/) AND an in-place upgrade (re-applied on every
-- `php artisan db:wire-up`).  Existing rows keep row_hmac='' and prev_hmac=''
-- and are intentionally outside the chain — they remain UPDATE/DELETE-blocked
-- by the trigger below but aren't cryptographically linked.
ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS prev_hmac VARCHAR(64) NOT NULL DEFAULT '';
ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS row_hmac  VARCHAR(64) NOT NULL DEFAULT '';

-- ── 1. The immutability trigger (kept from Phase 2) ─────────────────────────
CREATE OR REPLACE FUNCTION prevent_audit_log_modifications()
RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'audit_log is append-only — % is not permitted', TG_OP;
END;
$$;

-- ── 2. The canonical-payload + HMAC helper used by both trigger AND verifier
CREATE OR REPLACE FUNCTION audit_log_compute_hmac(prev_hmac_arg TEXT, row_audit audit_log)
RETURNS TEXT
LANGUAGE plpgsql STABLE AS $$
DECLARE
    secret  TEXT;
    payload TEXT;
BEGIN
    -- `current_setting(..., true)` returns NULL instead of erroring if the
    -- GUC was never set — which is exactly the state during fresh-install
    -- before scripts/seed-audit-key.sh runs.  In that case we emit '' as
    -- the hmac and verify-time treats those rows as pre-chain.
    secret := current_setting('cphc.audit_secret', true);
    IF secret IS NULL OR secret = '' THEN
        RETURN '';
    END IF;

    -- Canonical payload: every field, pipe-separated, NULL→''.  jsonb::text
    -- already produces a normalised (sorted-key) representation, so the
    -- serialisation is deterministic across PHP versions / locales — that
    -- matters because the verifier re-runs this same function later and
    -- must compute byte-identical input.
    payload :=
        COALESCE(row_audit.timestamp::text, '')    || '|' ||
        COALESCE(row_audit.user_id::text, '')      || '|' ||
        COALESCE(row_audit.action_type, '')        || '|' ||
        COALESCE(row_audit.entity_type, '')        || '|' ||
        COALESCE(row_audit.entity_id::text, '')    || '|' ||
        COALESCE(row_audit.before_value::text, '') || '|' ||
        COALESCE(row_audit.after_value::text, '')  || '|' ||
        COALESCE(row_audit.reason, '')             || '|' ||
        COALESCE(row_audit.ip_address, '')         || '|' ||
        COALESCE(row_audit.user_agent, '');

    RETURN encode(hmac(prev_hmac_arg || payload, secret, 'sha256'), 'hex');
END;
$$;

-- ── 3. The BEFORE-INSERT chain-linker ───────────────────────────────────────
CREATE OR REPLACE FUNCTION audit_log_chain_link()
RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    prev TEXT;
BEGIN
    -- Serialise chain inserts so two concurrent INSERTs can't both read the
    -- same prev and produce two children of the same parent.  Held only for
    -- the duration of the current transaction; cost is microseconds for a
    -- single-kiosk POS where concurrent audit writes are rare anyway.
    PERFORM pg_advisory_xact_lock(hashtext('cphc.audit_log_chain'));

    -- Find the previous chain tip — the highest id whose row_hmac was set.
    -- Rows that were inserted before Phase 3 (or while the secret GUC was
    -- not yet seeded) carry row_hmac='' and are intentionally NOT part of
    -- the chain.  They remain immutable via the trigger above; they just
    -- aren't cryptographically linked.
    SELECT row_hmac INTO prev
    FROM audit_log
    WHERE row_hmac <> ''
    ORDER BY id DESC
    LIMIT 1;

    NEW.prev_hmac := COALESCE(prev, '');
    NEW.row_hmac  := audit_log_compute_hmac(NEW.prev_hmac, NEW);

    RETURN NEW;
END;
$$;

-- ── 4. Wire up the triggers (drop any old variants first) ───────────────────
DO $$
BEGIN
    EXECUTE 'DROP TRIGGER IF EXISTS prevent_audit_modifications     ON audit_log';
    EXECUTE 'DROP TRIGGER IF EXISTS prevent_audit_log_modifications ON audit_log';
    EXECUTE 'DROP TRIGGER IF EXISTS audit_log_chain_link            ON audit_log';

    EXECUTE 'CREATE TRIGGER prevent_audit_log_modifications
             BEFORE UPDATE OR DELETE ON audit_log
             FOR EACH ROW EXECUTE FUNCTION prevent_audit_log_modifications()';

    EXECUTE 'CREATE TRIGGER audit_log_chain_link
             BEFORE INSERT ON audit_log
             FOR EACH ROW EXECUTE FUNCTION audit_log_chain_link()';
END;
$$;
