-- Extensions required by the CPHC schema + triggers.
--   pgcrypto:  hmac() used by audit_log chain trigger (Phase 3).
--   uuid-ossp: not currently used, but reserve for future.
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
