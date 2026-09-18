-- Run this against an existing database. schema.sql only applies on a
-- fresh install (docker-compose's initdb only runs once against an empty
-- volume), so anyone with a DB already set up needs this separately.
ALTER TABLE users ADD COLUMN IF NOT EXISTS fcm_token VARCHAR(255);
