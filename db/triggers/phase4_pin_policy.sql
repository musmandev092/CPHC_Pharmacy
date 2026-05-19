-- CPHC Pharmacy — Phase 4 PIN policy schema migration.
--
-- Idempotent.  Adds pin rotation columns to users and the pin_history table.
-- Re-applied on every `php artisan db:wire-up` call from scripts/deploy.sh,
-- so an existing install picks up the new columns without a data wipe.
--
-- Mirrors the additions in db/schema/init.sql for the same tables, so a
-- fresh install via /docker-entrypoint-initdb.d/ also has them.

ALTER TABLE "users" ADD COLUMN IF NOT EXISTS "pin_changed_at"  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE "users" ADD COLUMN IF NOT EXISTS "must_rotate_pin" BOOLEAN     NOT NULL DEFAULT false;

CREATE TABLE IF NOT EXISTS "pin_history" (
    "id"         BIGSERIAL    NOT NULL,
    "user_id"    BIGINT       NOT NULL,
    "pin_hash"   VARCHAR(255) NOT NULL,
    "changed_at" TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pin_history_pkey"      PRIMARY KEY ("id"),
    CONSTRAINT "pin_history_user_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS "pin_history_user_changed_idx" ON "pin_history" ("user_id", "changed_at" DESC);

-- Seed pin_changed_at for legacy rows so they aren't immediately expired
-- by the rotation middleware.  This gives existing users 90 days from
-- Phase-4 deploy before they must rotate.
UPDATE "users" SET "pin_changed_at" = CURRENT_TIMESTAMP WHERE "pin_changed_at" IS NULL;
