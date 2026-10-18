-- Adds URLs for generated thumbnail variants without deleting existing data.
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS attachment_thumbnail_url TEXT;
ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_thumbnail_url TEXT;
