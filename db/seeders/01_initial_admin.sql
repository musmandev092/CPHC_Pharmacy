-- CPHC Pharmacy POS — initial admin seed.
--
-- Creates a single ADMIN user on a fresh database so the operator can
-- log in for the first time.  Idempotent: ON CONFLICT DO NOTHING.
--
-- USERNAME:  admin
-- PIN:       472913
-- ROLE:      ADMIN
--
-- You MUST change this PIN at first login.  The 90-day rotation policy
-- counts from the moment this row was inserted, so the system will
-- prompt you on first login anyway.
--
-- The hash below is bcrypt cost 12 of '472913'.  Regenerate if you want
-- a different default (php -r "echo password_hash('YOUR_PIN', PASSWORD_BCRYPT, ['cost'=>12]);").

INSERT INTO users (full_name, username, pin_hash, role, is_active, must_rotate_pin)
VALUES (
    'Administrator',
    'admin',
    '$2y$12$7FVQGjyhVZfhjAeQ9bLHNulGgnaSwGNEcxwim0Lo9S1bzY8dW0V5q',
    'ADMIN',
    TRUE,
    TRUE   -- force PIN rotation on first login
)
ON CONFLICT (username) DO NOTHING;
