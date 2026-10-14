# Running SupportAssist Backend

## Local development

1. Install PHP 8.3+ and PostgreSQL, or use the existing local Docker Compose setup.
2. Copy `.env.example` to `.env` and configure the local database credentials and a unique `JWT_SECRET`.
3. Create the schema and category seed using `database/schema.sql` and `database/seed.sql` if your database has not been initialized.
4. Start the development server:

   ```bash
   php -S localhost:8080 -t public
   ```

5. Test the service:

   ```bash
   curl -i http://localhost:8080/health
   ```

Create the first administrator with a unique password hash using the instructions in `DEPLOY_RENDER_SUPABASE.md`. This project intentionally no longer ships a known default administrator account.

## Database settings

Use the values configured in your local `.env`. Do not put real database passwords in this file, in scripts, or in Git-tracked documentation. `.env` is ignored by Git; `.env.example` contains placeholders only.

## Push notifications (optional)

1. If a Firebase service-account key was ever shared or committed, revoke it and generate a replacement.
2. Store the replacement JSON outside the public document root and outside Git. For Render, use a Secret File at `/etc/secrets/firebase-service-account.json`.
3. Set `FCM_PROJECT_ID` and `FCM_SERVICE_ACCOUNT_PATH` in the environment. Leave `FCM_PROJECT_ID` unset if push notifications are not required.
4. The Android client should call `POST /api/user/fcm-token` after login and when its Firebase messaging token changes.

## Deployment

Follow [`DEPLOY_RENDER_SUPABASE.md`](DEPLOY_RENDER_SUPABASE.md) for the Render Docker service, Supabase Session Pooler, verified TLS, environment variables, database setup, and free-tier limitations.

## Notes

- Render's free filesystem is ephemeral. Ticket attachments written to `storage/uploads` may disappear after redeploys or instance replacement. Move attachments to durable object storage before relying on them.
- The file-based rate limiter is best-effort and can reset after redeploys.
- Forgot-password is disabled in production until a real email provider is integrated; in local development, reset tokens are logged for testing only. Do not enable token logging in production.
