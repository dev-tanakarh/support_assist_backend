-- ============================================================================
-- SupportAssist Helpdesk — PostgreSQL Schema
-- ============================================================================
-- Design notes (read these before you present this project):
--  * Native ENUM types are used because the spec explicitly asked for them.
--    In a system where statuses/roles change often, a lookup table with a
--    FK is usually the more maintainable choice (adding an enum value is a
--    schema migration; adding a row isn't). This tradeoff is worth being
--    able to explain in an interview.
--  * Ticket IDs use a human-readable TKT-YYYY-XXXX format. Postgres SERIAL
--    can't do this directly, so we keep a per-year counter table and
--    increment it inside a transaction with a row lock (see
--    next_ticket_number() below). This is a common real-world pattern for
--    "readable" invoice/ticket/order numbers.
--  * search_vector + a GIN index gives tickets full-text search
--    (GET /api/tickets?q=...) without bolting on Elasticsearch — a good
--    example of using the database you already have properly.
-- ============================================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- for gen_random_uuid()

-- ---------------------------------------------------------------------------
-- Enum types
-- ---------------------------------------------------------------------------
DO $$ BEGIN
    CREATE TYPE user_type_enum AS ENUM ('ADMIN', 'TECHNICIAN', 'END_USER');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
    CREATE TYPE ticket_status_enum AS ENUM ('OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
    CREATE TYPE ticket_priority_enum AS ENUM ('HIGH', 'MEDIUM', 'LOW');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
    CREATE TYPE alert_type_enum AS ENUM
        ('STATUS_CHANGE', 'ASSIGNMENT', 'TECH_ACCEPTED', 'NEW_TICKET', 'REVIEW_RECEIVED', 'SYSTEM');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- ---------------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name            VARCHAR(120) NOT NULL,
    email           VARCHAR(160) NOT NULL UNIQUE,
    phone           VARCHAR(30),
    avatar_url      TEXT,
    avatar_thumbnail_url TEXT,
    password_hash   VARCHAR(255) NOT NULL,
    user_type       user_type_enum NOT NULL DEFAULT 'END_USER',
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    fcm_token       VARCHAR(255),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_url TEXT;
ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_thumbnail_url TEXT;

CREATE INDEX IF NOT EXISTS idx_users_user_type ON users(user_type);

-- ---------------------------------------------------------------------------
-- ticket_categories
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticket_categories (
    id      SERIAL PRIMARY KEY,
    name    VARCHAR(100) NOT NULL UNIQUE
);

-- ---------------------------------------------------------------------------
-- Ticket numbering: per-year sequence -> TKT-YYYY-XXXX
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticket_number_counters (
    year        INT PRIMARY KEY,
    last_value  INT NOT NULL DEFAULT 0
);

CREATE OR REPLACE FUNCTION next_ticket_number(p_year INT)
RETURNS VARCHAR AS $$
DECLARE
    v_next INT;
BEGIN
    INSERT INTO ticket_number_counters (year, last_value)
    VALUES (p_year, 1)
    ON CONFLICT (year) DO UPDATE
        SET last_value = ticket_number_counters.last_value + 1
    RETURNING last_value INTO v_next;

    RETURN 'TKT-' || p_year::TEXT || '-' || LPAD(v_next::TEXT, 4, '0');
END;
$$ LANGUAGE plpgsql;

-- ---------------------------------------------------------------------------
-- tickets
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tickets (
    id                      VARCHAR(20) PRIMARY KEY,
    user_id                 UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    subject                 VARCHAR(200) NOT NULL,
    description             TEXT NOT NULL,
    category_id             INT REFERENCES ticket_categories(id),
    assigned_technician_id  UUID REFERENCES users(id),
    attachment_url          TEXT,
    attachment_thumbnail_url TEXT,
    department              VARCHAR(100),
    device_type             VARCHAR(100),
    room                    VARCHAR(100),
    status                  ticket_status_enum NOT NULL DEFAULT 'OPEN',
    priority                ticket_priority_enum NOT NULL DEFAULT 'MEDIUM',
    due_at                  TIMESTAMPTZ,          -- SLA deadline, set on creation based on priority
    resolved_at             TIMESTAMPTZ,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    search_vector           TSVECTOR
);

ALTER TABLE tickets ADD COLUMN IF NOT EXISTS attachment_thumbnail_url TEXT;

CREATE INDEX IF NOT EXISTS idx_tickets_user_id ON tickets(user_id);
CREATE INDEX IF NOT EXISTS idx_tickets_status ON tickets(status);
CREATE INDEX IF NOT EXISTS idx_tickets_technician ON tickets(assigned_technician_id);
CREATE INDEX IF NOT EXISTS idx_tickets_search ON tickets USING GIN(search_vector);

CREATE OR REPLACE FUNCTION tickets_search_vector_update() RETURNS trigger AS $$
BEGIN
    NEW.search_vector :=
        setweight(to_tsvector('english', coalesce(NEW.subject, '')), 'A') ||
        setweight(to_tsvector('english', coalesce(NEW.description, '')), 'B');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_tickets_search_vector ON tickets;
CREATE TRIGGER trg_tickets_search_vector
    BEFORE INSERT OR UPDATE OF subject, description ON tickets
    FOR EACH ROW EXECUTE FUNCTION tickets_search_vector_update();

CREATE OR REPLACE FUNCTION tickets_set_updated_at() RETURNS trigger AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_tickets_updated_at ON tickets;
CREATE TRIGGER trg_tickets_updated_at
    BEFORE UPDATE ON tickets
    FOR EACH ROW EXECUTE FUNCTION tickets_set_updated_at();

-- ---------------------------------------------------------------------------
-- ticket_status_history — audit trail, also powers "status history" in
-- GET /api/tickets/{id}
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticket_status_history (
    id          SERIAL PRIMARY KEY,
    ticket_id   VARCHAR(20) NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    old_status  ticket_status_enum,
    new_status  ticket_status_enum NOT NULL,
    changed_by  UUID REFERENCES users(id),
    note        VARCHAR(255),
    changed_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_status_history_ticket ON ticket_status_history(ticket_id);

-- ---------------------------------------------------------------------------
-- alerts
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alerts (
    id          SERIAL PRIMARY KEY,
    user_id     UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    ticket_id   VARCHAR(20) REFERENCES tickets(id) ON DELETE CASCADE,
    title       VARCHAR(150) NOT NULL,
    message     VARCHAR(255),
    type        alert_type_enum NOT NULL DEFAULT 'SYSTEM',
    color_code  VARCHAR(20) DEFAULT '#2196F3',
    is_read     BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_alerts_user_id ON alerts(user_id, created_at DESC);

-- ---------------------------------------------------------------------------
-- reviews — end-users rate resolved tickets
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reviews (
    id              SERIAL PRIMARY KEY,
    ticket_id       VARCHAR(20) NOT NULL UNIQUE REFERENCES tickets(id) ON DELETE CASCADE,
    user_id         UUID NOT NULL REFERENCES users(id),
    technician_id   UUID REFERENCES users(id),
    rating          SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment         TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- refresh_tokens — supports short-lived access tokens + longer refresh flow
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id     UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  VARCHAR(255) NOT NULL,
    expires_at  TIMESTAMPTZ NOT NULL,
    revoked     BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_refresh_tokens_user ON refresh_tokens(user_id);

-- ---------------------------------------------------------------------------
-- password_resets — forgot-password tokens. Never store the raw token, only
-- its hash (same pattern as refresh_tokens) — see AuthController::forgotPassword.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id     UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  VARCHAR(255) NOT NULL,
    expires_at  TIMESTAMPTZ NOT NULL,
    used        BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets(user_id);

CREATE TABLE IF NOT EXISTS images (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,

    ticket_id VARCHAR(20) NOT NULL
        REFERENCES tickets(id) ON DELETE CASCADE,

    uploaded_by UUID NOT NULL
        REFERENCES users(id) ON DELETE CASCADE,

    original_filename VARCHAR(255),

    file_path TEXT NOT NULL,

    mime_type VARCHAR(100) NOT NULL,

    file_size BIGINT NOT NULL CHECK (file_size >= 0),

    uploaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_images_ticket_id
    ON images(ticket_id);

CREATE INDEX IF NOT EXISTS idx_images_uploaded_by
    ON images(uploaded_by);
