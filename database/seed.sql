-- Idempotent seed data. This file intentionally creates categories only;
-- create the first administrator with a unique password hash (see
-- DEPLOY_RENDER_SUPABASE.md) rather than shipping a known default password.

INSERT INTO ticket_categories (name) VALUES
    ('Network Issues'),
    ('Software Issues'),
    ('Hardware Issues'),
    ('Account & Access'),
    ('Printer & Peripherals'),
    ('Email & Communication')
ON CONFLICT (name) DO NOTHING;
