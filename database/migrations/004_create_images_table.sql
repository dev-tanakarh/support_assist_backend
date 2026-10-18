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