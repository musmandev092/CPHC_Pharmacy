-- CPHC Pharmacy — Phase 5 DRAP compliance schema migration.
--
-- Idempotent.  Re-applied on every scripts/deploy.sh run.
--   * sales gains the prescriber license + narcotic witness columns
--   * new z_report_archive table holds signed copies of each day-close
--     report, immutable via the same trigger pattern as audit_log

-- ── sales: narcotic capture columns ─────────────────────────────────────────
ALTER TABLE "sales" ADD COLUMN IF NOT EXISTS "prescriber_license_number" VARCHAR(50);
ALTER TABLE "sales" ADD COLUMN IF NOT EXISTS "narcotic_witness_user_id"  BIGINT;
ALTER TABLE "sales" ADD COLUMN IF NOT EXISTS "narcotic_witness_at"       TIMESTAMPTZ;

-- FK only if not already there
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'sales_narcotic_witness_user_fkey'
          AND conrelid = 'sales'::regclass
    ) THEN
        ALTER TABLE "sales" ADD CONSTRAINT "sales_narcotic_witness_user_fkey"
            FOREIGN KEY ("narcotic_witness_user_id") REFERENCES "users"("id")
            ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END
$$;

-- Index for the narcotic register report
CREATE INDEX IF NOT EXISTS "sales_narcotic_idx" ON "sales" ("sold_at" DESC)
    WHERE "has_controlled_drug" = true;

-- ── z_report_archive: tamper-evident day-close history ──────────────────────
CREATE TABLE IF NOT EXISTS "z_report_archive" (
    "id"               BIGSERIAL    NOT NULL,
    "session_id"       BIGINT       NOT NULL,
    "generated_at"     TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "generated_by"     BIGINT,
    "payload"          JSONB        NOT NULL,
    "signature_hex"    VARCHAR(64)  NOT NULL,
    CONSTRAINT "z_report_archive_pkey"      PRIMARY KEY ("id"),
    CONSTRAINT "z_report_archive_session_fkey"
        FOREIGN KEY ("session_id") REFERENCES "cashier_sessions"("id") ON DELETE RESTRICT,
    CONSTRAINT "z_report_archive_generated_by_fkey"
        FOREIGN KEY ("generated_by") REFERENCES "users"("id") ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS "z_report_archive_session_idx" ON "z_report_archive" ("session_id");
CREATE INDEX IF NOT EXISTS "z_report_archive_generated_at_idx" ON "z_report_archive" ("generated_at" DESC);

-- Immutability trigger — same shape as audit_log's.  No UPDATE / DELETE,
-- ever.  A day-close record is a regulator's evidence; rewriting it is
-- a control breach.
CREATE OR REPLACE FUNCTION prevent_z_report_archive_modifications()
RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'z_report_archive is append-only — % is not permitted', TG_OP;
END;
$$;

DROP TRIGGER IF EXISTS prevent_z_report_archive_modifications ON z_report_archive;
CREATE TRIGGER prevent_z_report_archive_modifications
    BEFORE UPDATE OR DELETE ON z_report_archive
    FOR EACH ROW EXECUTE FUNCTION prevent_z_report_archive_modifications();
