-- ============================================================================
-- Seed data
-- Password for the default admin is 'admin123', hashed with PHP's
-- password_hash() (Bcrypt). Generated once and pasted in as a literal so
-- this file has no runtime dependency — regenerate it yourself if you
-- change the password (see database/generate_admin_hash.php).
-- ============================================================================

INSERT INTO ticket_categories (name) VALUES
    ('Network Issues'),
    ('Software Issues'),
    ('Hardware Issues'),
    ('Account & Access'),
    ('Printer & Peripherals'),
    ('Email & Communication')
ON CONFLICT (name) DO NOTHING;

-- Default admin: admin@helpdesk.com / admin123
-- Hash below was generated and verified against 'admin123' (bcrypt, cost 10).
-- PHP's password_verify() accepts the $2b$ prefix the same as $2y$, so this
-- works directly with the PHP password_hash()/password_verify() functions
-- used in AuthController. Regenerate with database/generate_admin_hash.php
-- if you want a different password.
INSERT INTO users (name, email, phone, password_hash, user_type)
VALUES (
    'System Administrator',
    'admin@helpdesk.com',
    '+260000000000',
    '$2b$10$iZ9MEfPISBz5p5nDIcI99OZCHXCCIo8AkC52zJkEB3uAK0k7kC916', -- admin123
    'ADMIN'
)
ON CONFLICT (email) DO NOTHING;
