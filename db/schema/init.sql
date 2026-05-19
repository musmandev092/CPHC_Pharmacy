-- =============================================================================
-- CPHC Pharmacy — fresh-database schema bootstrap
-- =============================================================================
--
-- Mounted into Postgres's /docker-entrypoint-initdb.d/ folder. Runs ONCE,
-- only when the postgres-data volume is empty. On a clinic PC that already
-- has production data this script is skipped automatically — no risk.
--
-- Origin: concatenated from the legacy Prisma migrations at
--   app/prisma/migrations/  (preserved verbatim, only re-ordered for safety):
--     1) 20260509111236_init                       — enums + tables + indexes + FKs
--     2) 20260511000002_medicine_units_and_forms   — extra MedicineForm enum values + form_custom + reorder_unit
--     3) 20260514000003_add_batch_qty_check        — non-negative CHECK constraints
--     4) 20260514000004_add_rate_limits_table      — rate_limits table
--     5) 20260510000001_add_audit_log_trigger      — append-only trigger
--
-- The audit trigger is reapplied separately by db/triggers/audit_log_immutable.sql
-- (also mounted into /docker-entrypoint-initdb.d/) so a partial init still ends
-- up with a working trigger.
-- =============================================================================


-- ── 1) Enums ────────────────────────────────────────────────────────────────
CREATE TYPE "UserRole"           AS ENUM ('ADMIN', 'MANAGER', 'CASHIER');

-- MedicineForm: combined values from migrations 1 + 2 (all 22 final values)
CREATE TYPE "MedicineForm"       AS ENUM (
    'TABLET', 'CAPSULE', 'SOFT_GEL_CAPSULE',
    'SYRUP', 'SUSPENSION',
    'INJECTION', 'INJECTION_AMPOULE', 'INJECTION_VIAL',
    'CREAM', 'OINTMENT', 'GEL',
    'DROPS', 'INHALER',
    'PATCH', 'SUPPOSITORY',
    'SACHET', 'POWDER',
    'IV_FLUID',
    'LOTION', 'SPRAY',
    'DEVICE', 'OTHER'
);

CREATE TYPE "ControlledSchedule" AS ENUM ('NONE', 'SCHEDULE_G', 'SCHEDULE_H', 'NARCOTIC');
CREATE TYPE "TaxCode"            AS ENUM ('EXEMPT', 'STANDARD_18', 'REDUCED', 'ZERO_RATED');
CREATE TYPE "GrnStatus"          AS ENUM ('DRAFT', 'POSTED', 'CANCELLED');
CREATE TYPE "PaymentMode"        AS ENUM ('CASH', 'CARD', 'OTHER');
CREATE TYPE "SaleStatus"         AS ENUM ('COMPLETED', 'VOIDED', 'REFUNDED_PARTIAL', 'REFUNDED_FULL');
CREATE TYPE "ReturnReason"       AS ENUM ('CUSTOMER_CHANGED_MIND', 'DAMAGED', 'WRONG_ITEM', 'ADVERSE_REACTION', 'EXPIRED', 'OTHER');
CREATE TYPE "ReturnStatus"       AS ENUM ('PENDING_REVIEW', 'APPROVED_RESTOCK', 'RETURN_TO_SUPPLIER', 'WRITE_OFF');
CREATE TYPE "AdjustmentReason"   AS ENUM ('DAMAGE', 'EXPIRY_WRITEOFF', 'SHRINKAGE', 'COUNT_CORRECTION', 'SAMPLE', 'DONATION', 'OTHER');
CREATE TYPE "MovementType"       AS ENUM ('GRN_RECEIPT', 'SALE', 'RETURN_QUARANTINE', 'RETURN_RESTOCK', 'ADJUSTMENT', 'WRITE_OFF', 'EXPIRY_BLOCK');
CREATE TYPE "SessionStatus"      AS ENUM ('OPEN', 'CLOSED', 'RECONCILED');


-- ── 2) Tables ───────────────────────────────────────────────────────────────

CREATE TABLE "branches" (
    "id"         SERIAL       NOT NULL,
    "name"       VARCHAR(120) NOT NULL,
    "address"    TEXT,
    "phone"      VARCHAR(40),
    "is_active"  BOOLEAN      NOT NULL DEFAULT true,
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "branches_pkey" PRIMARY KEY ("id")
);

CREATE TABLE "users" (
    "id"               BIGSERIAL    NOT NULL,
    "full_name"        VARCHAR(120) NOT NULL,
    "username"         VARCHAR(60)  NOT NULL,
    "pin_hash"         VARCHAR(255) NOT NULL,
    "password_hash"    VARCHAR(255),
    "role"             "UserRole"   NOT NULL,
    "is_active"        BOOLEAN      NOT NULL DEFAULT true,
    "last_login_at"    TIMESTAMPTZ,
    -- Phase 4: 90-day PIN rotation policy.  pin_changed_at is read by the
    -- EnforcePinRotation middleware; must_rotate_pin is set by an admin who
    -- resets someone else's PIN, forcing rotation on next login.
    "pin_changed_at"   TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "must_rotate_pin"  BOOLEAN      NOT NULL DEFAULT false,
    "created_at"       TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at"       TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "deleted_at"       TIMESTAMPTZ,
    "branch_id"        INTEGER      NOT NULL DEFAULT 1,
    CONSTRAINT "users_pkey" PRIMARY KEY ("id")
);

-- Phase 4: PIN history.  PinPolicy refuses any new PIN whose hash matches a
-- row in this table for the same user → enforces "no repeat of last 5".
CREATE TABLE "pin_history" (
    "id"          BIGSERIAL    NOT NULL,
    "user_id"     BIGINT       NOT NULL,
    "pin_hash"    VARCHAR(255) NOT NULL,
    "changed_at"  TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "pin_history_pkey"     PRIMARY KEY ("id"),
    CONSTRAINT "pin_history_user_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE CASCADE
);
CREATE INDEX "pin_history_user_changed_idx" ON "pin_history" ("user_id", "changed_at" DESC);

CREATE TABLE "suppliers" (
    "id"             BIGSERIAL    NOT NULL,
    "name"           VARCHAR(160) NOT NULL,
    "contact_phone"  VARCHAR(40),
    "ntn"            VARCHAR(40),
    "address"        TEXT,
    "booker_name"    VARCHAR(120),
    "salesman_name"  VARCHAR(120),
    "payment_terms"  VARCHAR(60)  DEFAULT 'CASH',
    "notes"          TEXT,
    "is_active"      BOOLEAN      NOT NULL DEFAULT true,
    "created_at"     TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at"     TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "deleted_at"     TIMESTAMPTZ,
    CONSTRAINT "suppliers_pkey" PRIMARY KEY ("id")
);

-- medicines: includes form_custom + reorder_unit from migration 2
CREATE TABLE "medicines" (
    "id"                    BIGSERIAL              NOT NULL,
    "sku"                   VARCHAR(40)            NOT NULL,
    "primary_barcode"       VARCHAR(60),
    "brand_name"            VARCHAR(160)           NOT NULL,
    "generic_name"          VARCHAR(160)           NOT NULL,
    "strength"              VARCHAR(60),
    "form"                  "MedicineForm"         NOT NULL,
    "form_custom"           VARCHAR(60),
    "manufacturer"          VARCHAR(160),
    "therapeutic_category"  VARCHAR(120),
    "purchase_unit"         VARCHAR(40)            NOT NULL,
    "base_unit"             VARCHAR(40)            NOT NULL,
    "units_per_purchase"    INTEGER                NOT NULL,
    "prescription_required" BOOLEAN                NOT NULL DEFAULT false,
    "controlled_schedule"   "ControlledSchedule"   NOT NULL DEFAULT 'NONE',
    "tax_code_value"        "TaxCode"              NOT NULL DEFAULT 'EXEMPT',
    "reorder_level"         INTEGER                DEFAULT 0,
    "reorder_quantity"      INTEGER                DEFAULT 0,
    "reorder_unit"          VARCHAR(20)            NOT NULL DEFAULT 'PURCHASE',
    "is_active"             BOOLEAN                NOT NULL DEFAULT true,
    "created_at"            TIMESTAMPTZ            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at"            TIMESTAMPTZ            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "deleted_at"            TIMESTAMPTZ,
    CONSTRAINT "medicines_pkey" PRIMARY KEY ("id")
);

CREATE TABLE "batches" (
    "id"               BIGSERIAL      NOT NULL,
    "branch_id"        INTEGER        NOT NULL DEFAULT 1,
    "medicine_id"      BIGINT         NOT NULL,
    "supplier_id"      BIGINT,
    "grn_id"           BIGINT,
    "batch_number"     VARCHAR(50)    NOT NULL,
    "distributor_ref"  VARCHAR(60),
    "expiry_date"      DATE           NOT NULL,
    "received_qty"     INTEGER        NOT NULL,
    "foc_qty"          INTEGER        NOT NULL DEFAULT 0,
    "current_qty"      INTEGER        NOT NULL,
    "cost_per_unit"    DECIMAL(12,4)  NOT NULL,
    "mrp_per_unit"     DECIMAL(12,2)  NOT NULL,
    "is_quarantined"   BOOLEAN        NOT NULL DEFAULT false,
    "is_expired"       BOOLEAN        NOT NULL DEFAULT false,
    "notes"            TEXT,
    "created_at"       TIMESTAMPTZ    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at"       TIMESTAMPTZ    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "batches_pkey" PRIMARY KEY ("id"),
    -- non-negative CHECKs from migration 3, inlined:
    CONSTRAINT "batch_qty_nonneg"          CHECK ("current_qty"  >= 0),
    CONSTRAINT "batch_received_qty_nonneg" CHECK ("received_qty" >= 0),
    CONSTRAINT "batch_foc_qty_nonneg"      CHECK ("foc_qty"      >= 0)
);

CREATE TABLE "grn_documents" (
    "id"                 BIGSERIAL       NOT NULL,
    "branch_id"          INTEGER         NOT NULL DEFAULT 1,
    "grn_number"         VARCHAR(40)     NOT NULL,
    "supplier_id"        BIGINT          NOT NULL,
    "invoice_number"     VARCHAR(60),
    "invoice_date"       DATE,
    "invoice_image_path" VARCHAR(255),
    "received_by"        BIGINT          NOT NULL,
    "posted_by"          BIGINT,
    "posted_at"          TIMESTAMPTZ,
    "subtotal"           DECIMAL(12,2)   NOT NULL DEFAULT 0,
    "tax_total"          DECIMAL(12,2)   NOT NULL DEFAULT 0,
    "discount_total"     DECIMAL(12,2)   NOT NULL DEFAULT 0,
    "grand_total"        DECIMAL(12,2)   NOT NULL DEFAULT 0,
    "status"             "GrnStatus"     NOT NULL DEFAULT 'DRAFT',
    "notes"              TEXT,
    "created_at"         TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at"         TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "grn_documents_pkey" PRIMARY KEY ("id")
);

CREATE TABLE "grn_lines" (
    "id"                BIGSERIAL      NOT NULL,
    "grn_id"            BIGINT         NOT NULL,
    "medicine_id"       BIGINT         NOT NULL,
    "batch_id"          BIGINT,
    "batch_number"      VARCHAR(50)    NOT NULL,
    "expiry_date"       DATE           NOT NULL,
    "paid_qty"          INTEGER        NOT NULL,
    "foc_qty"           INTEGER        NOT NULL DEFAULT 0,
    "qty_in_base_units" INTEGER        NOT NULL,
    "unit_cost"         DECIMAL(12,4)  NOT NULL,
    "mrp_per_base_unit" DECIMAL(12,2)  NOT NULL,
    "line_total"        DECIMAL(12,2)  NOT NULL,
    "created_at"        TIMESTAMPTZ    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "grn_lines_pkey" PRIMARY KEY ("id")
);

CREATE TABLE "sales" (
    "id"                       BIGSERIAL      NOT NULL,
    "branch_id"                INTEGER        NOT NULL DEFAULT 1,
    "receipt_number"           VARCHAR(40)    NOT NULL,
    "cashier_id"               BIGINT         NOT NULL,
    "cashier_session_id"       BIGINT,
    "customer_name"            VARCHAR(160),
    "customer_phone"           VARCHAR(40),
    "customer_remarks"         VARCHAR(255),
    "has_controlled_drug"      BOOLEAN        NOT NULL DEFAULT false,
    "doctor_name"              VARCHAR(160),
    -- DRAP (Phase 5): mandatory captures for narcotic dispense.  License
    -- number is the prescribing physician's DRAP registration; witness_user_id
    -- is the second user who PIN-confirmed the dispense at the counter.
    "prescriber_license_number" VARCHAR(50),
    "narcotic_witness_user_id"  BIGINT,
    "narcotic_witness_at"       TIMESTAMPTZ,
    "patient_name"             VARCHAR(160),
    "patient_phone"            VARCHAR(40),
    "patient_address"          TEXT,
    "prescription_image_path"  VARCHAR(255),
    "subtotal"                 DECIMAL(12,2)  NOT NULL,
    "tax_total"                DECIMAL(12,2)  NOT NULL DEFAULT 0,
    "discount_total"           DECIMAL(12,2)  NOT NULL DEFAULT 0,
    "discount_authorized_by"   BIGINT,
    "pos_service_fee"          DECIMAL(12,2)  NOT NULL DEFAULT 0,
    "grand_total"              DECIMAL(12,2)  NOT NULL,
    "amount_tendered"          DECIMAL(12,2),
    "change_returned"          DECIMAL(12,2),
    "payment_mode"             "PaymentMode"  NOT NULL DEFAULT 'CASH',
    "status"                   "SaleStatus"   NOT NULL DEFAULT 'COMPLETED',
    "sold_at"                  TIMESTAMPTZ    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "created_at"               TIMESTAMPTZ    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "sales_pkey" PRIMARY KEY ("id")
);

CREATE TABLE "sale_items" (
    "id"                BIGSERIAL      NOT NULL,
    "sale_id"           BIGINT         NOT NULL,
    "medicine_id"       BIGINT         NOT NULL,
    "batch_id"          BIGINT         NOT NULL,
    "qty_in_base_units" INTEGER        NOT NULL,
    "sold_unit_label"   VARCHAR(40)    NOT NULL,
    "sold_unit_factor"  INTEGER        NOT NULL DEFAULT 1,
    "qty_sold_display"  INTEGER        NOT NULL,
    "unit_cost"         DECIMAL(12,4)  NOT NULL,
    "unit_mrp"          DECIMAL(12,2)  NOT NULL,
    "line_subtotal"     DECIMAL(12,2)  NOT NULL,
    "line_tax"          DECIMAL(12,2)  NOT NULL DEFAULT 0,
    "line_discount"     DECIMAL(12,2)  NOT NULL DEFAULT 0,
    "line_total"        DECIMAL(12,2)  NOT NULL,
    "created_at"        TIMESTAMPTZ    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "sale_items_pkey" PRIMARY KEY ("id")
);

CREATE TABLE "returns" (
    "id"                 BIGSERIAL       NOT NULL,
    "branch_id"          INTEGER         NOT NULL DEFAULT 1,
    "return_number"      VARCHAR(40)     NOT NULL,
    "original_sale_id"   BIGINT          NOT NULL,
    "sale_item_id"       BIGINT          NOT NULL,
    "medicine_id"        BIGINT          NOT NULL,
    "batch_id"           BIGINT          NOT NULL,
    "qty_returned_units" INTEGER         NOT NULL,
    "refund_amount"      DECIMAL(12,2)   NOT NULL,
    "reason"             "ReturnReason"  NOT NULL,
    "physical_condition" TEXT,
    "initiated_by"       BIGINT          NOT NULL,
    "authorized_by"      BIGINT,
    "status"             "ReturnStatus"  NOT NULL DEFAULT 'PENDING_REVIEW',
    "adjudicated_by"     BIGINT,
    "adjudicated_at"     TIMESTAMPTZ,
    "adjudication_notes" TEXT,
    "created_at"         TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at"         TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "returns_pkey" PRIMARY KEY ("id")
);

CREATE TABLE "stock_adjustments" (
    "id"                BIGSERIAL           NOT NULL,
    "branch_id"         INTEGER             NOT NULL DEFAULT 1,
    "adjustment_number" VARCHAR(40)         NOT NULL,
    "medicine_id"       BIGINT              NOT NULL,
    "batch_id"          BIGINT              NOT NULL,
    "qty_delta"         INTEGER             NOT NULL,
    "reason"            "AdjustmentReason"  NOT NULL,
    "notes"             TEXT,
    "performed_by"      BIGINT              NOT NULL,
    "cost_impact"       DECIMAL(12,2),
    "created_at"        TIMESTAMPTZ         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "stock_adjustments_pkey" PRIMARY KEY ("id")
);

CREATE TABLE "inventory_movements" (
    "id"            BIGSERIAL       NOT NULL,
    "branch_id"     INTEGER         NOT NULL DEFAULT 1,
    "medicine_id"   BIGINT          NOT NULL,
    "batch_id"      BIGINT          NOT NULL,
    "movement_type" "MovementType"  NOT NULL,
    "qty_delta"     INTEGER         NOT NULL,
    "qty_before"    INTEGER         NOT NULL,
    "qty_after"     INTEGER         NOT NULL,
    "ref_table"     VARCHAR(40),
    "ref_id"        BIGINT,
    "performed_by"  BIGINT,
    "created_at"    TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "inventory_movements_pkey" PRIMARY KEY ("id")
);

CREATE TABLE "cashier_sessions" (
    "id"                      BIGSERIAL        NOT NULL,
    "branch_id"               INTEGER          NOT NULL DEFAULT 1,
    "cashier_id"              BIGINT           NOT NULL,
    "opened_at"               TIMESTAMPTZ      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "closed_at"               TIMESTAMPTZ,
    "opening_float"           DECIMAL(12,2)    NOT NULL DEFAULT 0,
    "expected_cash_in_drawer" DECIMAL(12,2),
    "counted_cash"            DECIMAL(12,2),
    "cash_variance"           DECIMAL(12,2),
    "total_cash_sales"        DECIMAL(12,2)    NOT NULL DEFAULT 0,
    "total_refunds_paid"      DECIMAL(12,2)    NOT NULL DEFAULT 0,
    "total_sales_count"       INTEGER          NOT NULL DEFAULT 0,
    "status"                  "SessionStatus"  NOT NULL DEFAULT 'OPEN',
    "z_report_pdf_path"       VARCHAR(255),
    "notes"                   TEXT,
    "created_at"              TIMESTAMPTZ      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at"              TIMESTAMPTZ      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "cashier_sessions_pkey" PRIMARY KEY ("id")
);

CREATE TABLE "audit_log" (
    "id"           BIGSERIAL    NOT NULL,
    "timestamp"    TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "user_id"      BIGINT,
    "action_type"  VARCHAR(60)  NOT NULL,
    "entity_type"  VARCHAR(40)  NOT NULL,
    "entity_id"    BIGINT,
    "before_value" JSONB,
    "after_value"  JSONB,
    "reason"       TEXT,
    "ip_address"   VARCHAR(45),
    "user_agent"   VARCHAR(255),
    -- HMAC chain — see db/triggers/audit_log_immutable.sql.  Each new row's
    -- row_hmac is computed at INSERT time over (prev_hmac || canonical_payload)
    -- keyed with a secret stored OUTSIDE the database (host file mounted at
    -- /etc/cphc-pharmacy/audit.key, loaded into a per-role GUC).  Tampering
    -- with any audit row breaks the verifiable chain at that row and forward.
    "prev_hmac"    VARCHAR(64)  NOT NULL DEFAULT '',
    "row_hmac"     VARCHAR(64)  NOT NULL DEFAULT '',
    CONSTRAINT "audit_log_pkey" PRIMARY KEY ("id")
);

CREATE TABLE "settings" (
    "key"         VARCHAR(80)  NOT NULL,
    "value"       TEXT         NOT NULL,
    "description" TEXT,
    "updated_at"  TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_by"  BIGINT,
    CONSTRAINT "settings_pkey" PRIMARY KEY ("key")
);

-- rate_limits — from migration 4
CREATE TABLE "rate_limits" (
    "key"      VARCHAR(120)  NOT NULL,
    "count"    INTEGER       NOT NULL DEFAULT 1,
    "reset_at" TIMESTAMPTZ   NOT NULL,
    CONSTRAINT "rate_limits_pkey" PRIMARY KEY ("key")
);


-- ── 3) Indexes ──────────────────────────────────────────────────────────────
CREATE UNIQUE INDEX "users_username_key"                            ON "users"("username");
CREATE        INDEX "users_role_is_active_deleted_at_idx"           ON "users"("role", "is_active", "deleted_at");

CREATE        INDEX "suppliers_name_is_active_deleted_at_idx"       ON "suppliers"("name", "is_active", "deleted_at");

CREATE UNIQUE INDEX "medicines_sku_key"                             ON "medicines"("sku");
CREATE        INDEX "medicines_primary_barcode_idx"                 ON "medicines"("primary_barcode");
CREATE        INDEX "medicines_is_active_deleted_at_idx"            ON "medicines"("is_active", "deleted_at");

CREATE        INDEX "batches_medicine_id_expiry_date_idx"           ON "batches"("medicine_id", "expiry_date");
CREATE        INDEX "batches_expiry_date_idx"                       ON "batches"("expiry_date");
CREATE UNIQUE INDEX "batches_medicine_id_batch_number_expiry_date_key"
                                                                     ON "batches"("medicine_id", "batch_number", "expiry_date");

CREATE UNIQUE INDEX "grn_documents_grn_number_key"                  ON "grn_documents"("grn_number");
CREATE        INDEX "grn_documents_supplier_id_invoice_date_idx"    ON "grn_documents"("supplier_id", "invoice_date");
CREATE        INDEX "grn_documents_status_idx"                      ON "grn_documents"("status");

CREATE        INDEX "grn_lines_grn_id_idx"                          ON "grn_lines"("grn_id");

CREATE UNIQUE INDEX "sales_receipt_number_key"                      ON "sales"("receipt_number");
CREATE        INDEX "sales_sold_at_idx"                             ON "sales"("sold_at");
CREATE        INDEX "sales_cashier_id_sold_at_idx"                  ON "sales"("cashier_id", "sold_at");
CREATE        INDEX "sales_customer_phone_idx"                      ON "sales"("customer_phone");
CREATE        INDEX "sales_status_idx"                              ON "sales"("status");

CREATE        INDEX "sale_items_sale_id_idx"                        ON "sale_items"("sale_id");
CREATE        INDEX "sale_items_medicine_id_idx"                    ON "sale_items"("medicine_id");
CREATE        INDEX "sale_items_batch_id_idx"                       ON "sale_items"("batch_id");

CREATE UNIQUE INDEX "returns_return_number_key"                     ON "returns"("return_number");
CREATE        INDEX "returns_status_idx"                            ON "returns"("status");
CREATE        INDEX "returns_original_sale_id_idx"                  ON "returns"("original_sale_id");

CREATE UNIQUE INDEX "stock_adjustments_adjustment_number_key"       ON "stock_adjustments"("adjustment_number");
CREATE        INDEX "stock_adjustments_created_at_idx"              ON "stock_adjustments"("created_at");
CREATE        INDEX "stock_adjustments_batch_id_idx"                ON "stock_adjustments"("batch_id");

CREATE        INDEX "inventory_movements_batch_id_created_at_idx"   ON "inventory_movements"("batch_id", "created_at");
CREATE        INDEX "inventory_movements_medicine_id_created_at_idx"
                                                                     ON "inventory_movements"("medicine_id", "created_at");
CREATE        INDEX "inventory_movements_ref_table_ref_id_idx"      ON "inventory_movements"("ref_table", "ref_id");

CREATE        INDEX "cashier_sessions_cashier_id_opened_at_idx"     ON "cashier_sessions"("cashier_id", "opened_at");
CREATE        INDEX "cashier_sessions_status_idx"                   ON "cashier_sessions"("status");

CREATE        INDEX "audit_log_timestamp_idx"                       ON "audit_log"("timestamp" DESC);
CREATE        INDEX "audit_log_user_id_timestamp_idx"               ON "audit_log"("user_id", "timestamp" DESC);
CREATE        INDEX "audit_log_entity_type_entity_id_idx"           ON "audit_log"("entity_type", "entity_id");
CREATE        INDEX "audit_log_action_type_idx"                     ON "audit_log"("action_type");


-- ── 4) Foreign keys ─────────────────────────────────────────────────────────
ALTER TABLE "users"                ADD CONSTRAINT "users_branch_id_fkey"
    FOREIGN KEY ("branch_id")              REFERENCES "branches"("id")          ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE "batches"              ADD CONSTRAINT "batches_branch_id_fkey"
    FOREIGN KEY ("branch_id")              REFERENCES "branches"("id")          ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "batches"              ADD CONSTRAINT "batches_medicine_id_fkey"
    FOREIGN KEY ("medicine_id")            REFERENCES "medicines"("id")         ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "batches"              ADD CONSTRAINT "batches_supplier_id_fkey"
    FOREIGN KEY ("supplier_id")            REFERENCES "suppliers"("id")         ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE "batches"              ADD CONSTRAINT "batches_grn_id_fkey"
    FOREIGN KEY ("grn_id")                 REFERENCES "grn_documents"("id")     ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE "grn_documents"        ADD CONSTRAINT "grn_documents_branch_id_fkey"
    FOREIGN KEY ("branch_id")              REFERENCES "branches"("id")          ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "grn_documents"        ADD CONSTRAINT "grn_documents_supplier_id_fkey"
    FOREIGN KEY ("supplier_id")            REFERENCES "suppliers"("id")         ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "grn_documents"        ADD CONSTRAINT "grn_documents_received_by_fkey"
    FOREIGN KEY ("received_by")            REFERENCES "users"("id")             ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "grn_documents"        ADD CONSTRAINT "grn_documents_posted_by_fkey"
    FOREIGN KEY ("posted_by")              REFERENCES "users"("id")             ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE "grn_lines"            ADD CONSTRAINT "grn_lines_grn_id_fkey"
    FOREIGN KEY ("grn_id")                 REFERENCES "grn_documents"("id")     ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE "grn_lines"            ADD CONSTRAINT "grn_lines_medicine_id_fkey"
    FOREIGN KEY ("medicine_id")            REFERENCES "medicines"("id")         ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "grn_lines"            ADD CONSTRAINT "grn_lines_batch_id_fkey"
    FOREIGN KEY ("batch_id")               REFERENCES "batches"("id")           ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE "sales"                ADD CONSTRAINT "sales_branch_id_fkey"
    FOREIGN KEY ("branch_id")              REFERENCES "branches"("id")          ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "sales"                ADD CONSTRAINT "sales_cashier_id_fkey"
    FOREIGN KEY ("cashier_id")             REFERENCES "users"("id")             ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "sales"                ADD CONSTRAINT "sales_cashier_session_id_fkey"
    FOREIGN KEY ("cashier_session_id")     REFERENCES "cashier_sessions"("id")  ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE "sales"                ADD CONSTRAINT "sales_discount_authorized_by_fkey"
    FOREIGN KEY ("discount_authorized_by") REFERENCES "users"("id")             ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE "sale_items"           ADD CONSTRAINT "sale_items_sale_id_fkey"
    FOREIGN KEY ("sale_id")                REFERENCES "sales"("id")             ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE "sale_items"           ADD CONSTRAINT "sale_items_medicine_id_fkey"
    FOREIGN KEY ("medicine_id")            REFERENCES "medicines"("id")         ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "sale_items"           ADD CONSTRAINT "sale_items_batch_id_fkey"
    FOREIGN KEY ("batch_id")               REFERENCES "batches"("id")           ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE "returns"              ADD CONSTRAINT "returns_branch_id_fkey"
    FOREIGN KEY ("branch_id")              REFERENCES "branches"("id")          ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "returns"              ADD CONSTRAINT "returns_original_sale_id_fkey"
    FOREIGN KEY ("original_sale_id")       REFERENCES "sales"("id")             ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "returns"              ADD CONSTRAINT "returns_sale_item_id_fkey"
    FOREIGN KEY ("sale_item_id")           REFERENCES "sale_items"("id")        ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "returns"              ADD CONSTRAINT "returns_medicine_id_fkey"
    FOREIGN KEY ("medicine_id")            REFERENCES "medicines"("id")         ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "returns"              ADD CONSTRAINT "returns_batch_id_fkey"
    FOREIGN KEY ("batch_id")               REFERENCES "batches"("id")           ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "returns"              ADD CONSTRAINT "returns_initiated_by_fkey"
    FOREIGN KEY ("initiated_by")           REFERENCES "users"("id")             ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "returns"              ADD CONSTRAINT "returns_authorized_by_fkey"
    FOREIGN KEY ("authorized_by")          REFERENCES "users"("id")             ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE "returns"              ADD CONSTRAINT "returns_adjudicated_by_fkey"
    FOREIGN KEY ("adjudicated_by")         REFERENCES "users"("id")             ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE "stock_adjustments"    ADD CONSTRAINT "stock_adjustments_branch_id_fkey"
    FOREIGN KEY ("branch_id")              REFERENCES "branches"("id")          ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "stock_adjustments"    ADD CONSTRAINT "stock_adjustments_medicine_id_fkey"
    FOREIGN KEY ("medicine_id")            REFERENCES "medicines"("id")         ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "stock_adjustments"    ADD CONSTRAINT "stock_adjustments_batch_id_fkey"
    FOREIGN KEY ("batch_id")               REFERENCES "batches"("id")           ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "stock_adjustments"    ADD CONSTRAINT "stock_adjustments_performed_by_fkey"
    FOREIGN KEY ("performed_by")           REFERENCES "users"("id")             ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE "inventory_movements"  ADD CONSTRAINT "inventory_movements_branch_id_fkey"
    FOREIGN KEY ("branch_id")              REFERENCES "branches"("id")          ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "inventory_movements"  ADD CONSTRAINT "inventory_movements_medicine_id_fkey"
    FOREIGN KEY ("medicine_id")            REFERENCES "medicines"("id")         ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "inventory_movements"  ADD CONSTRAINT "inventory_movements_batch_id_fkey"
    FOREIGN KEY ("batch_id")               REFERENCES "batches"("id")           ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "inventory_movements"  ADD CONSTRAINT "inventory_movements_performed_by_fkey"
    FOREIGN KEY ("performed_by")           REFERENCES "users"("id")             ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE "cashier_sessions"     ADD CONSTRAINT "cashier_sessions_branch_id_fkey"
    FOREIGN KEY ("branch_id")              REFERENCES "branches"("id")          ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "cashier_sessions"     ADD CONSTRAINT "cashier_sessions_cashier_id_fkey"
    FOREIGN KEY ("cashier_id")             REFERENCES "users"("id")             ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE "settings"             ADD CONSTRAINT "settings_updated_by_fkey"
    FOREIGN KEY ("updated_by")             REFERENCES "users"("id")             ON DELETE SET NULL ON UPDATE CASCADE;


-- ── 5) Seed: default branch ────────────────────────────────────────────────
-- Every place in the app defaults branch_id = 1, so make sure that row exists.
INSERT INTO "branches" ("id", "name", "is_active")
    VALUES (1, 'CPHC Main', true)
    ON CONFLICT ("id") DO NOTHING;
