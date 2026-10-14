# SupportAssist backend — Render + Supabase deployment

This guide deploys the existing plain-PHP API as a Docker web service on Render and uses Supabase PostgreSQL. The Dockerfile at the repository root is for Render; the existing `docker/` development setup remains available for local use.

## Before you start: rotate exposed secrets

The source archive supplied for this deployment included a `.env` file and a Firebase service-account JSON file. They are deliberately **not included** in the deployment-ready archive. Treat the Firebase private key and any credentials from that archive as exposed:

1. In Google Cloud Console / Firebase, disable/delete the exposed service-account key and generate a replacement only if push notifications are required.
2. Change the Supabase database password if it was present in the old `.env` or has been shared/committed.
3. Generate a new `JWT_SECRET` and update any clients/services that depend on existing tokens; old tokens signed with the previous secret will stop validating.
4. Check Git history if the files were ever committed. Removing them from the latest commit does not erase them from history.

## 1. Create the Supabase project

1. Go to <https://supabase.com/dashboard> and create a project on the Free plan.
2. Choose a region close to your Render service and users.
3. Set a strong database password. Store it in a password manager.
4. Wait until the project is ready.
5. Open **Connect** and select **Session pooler**. Copy the pooler hostname and username exactly as shown. Use port `5432` for the session pooler.
6. In the database SSL settings / connection guide, download the Supabase root CA certificate used to verify the server certificate. Keep it as a file named `supabase-prod-ca.crt`.

Use the Session Pooler because it is suitable for persistent backend connections and supports IPv4 networks. Do not use the direct database hostname unless you have verified network compatibility for your hosting environment.

## 2. Create the schema and categories

In Supabase, open **SQL Editor → New query**.

1. Open `database/schema.sql` from this repository, copy the complete contents, paste it into the SQL Editor and run it.
2. Copy `database/seed.sql`, paste it into a new query and run it. This seeds ticket categories only; it intentionally does not create a known-password admin account.
3. The current `schema.sql` already includes the columns/tables from migrations `001`–`003`. For a fresh project, do not run those migrations again. If you are applying this to an existing database, review the schema changes first and take a backup.

### Create the first administrator safely

On your local computer, generate a password hash for a unique strong password:

```bash
php database/generate_admin_hash.php 'REPLACE_WITH_A_LONG_UNIQUE_PASSWORD'
```

Copy the hash printed by PHP. In Supabase SQL Editor, run the following after replacing the placeholders. The password itself is never stored in SQL, only its hash.

```sql
INSERT INTO users (name, email, phone, password_hash, user_type)
VALUES (
  'SupportAssist Administrator',
  'YOUR_ADMIN_EMAIL',
  NULL,
  'PASTE_THE_GENERATED_PASSWORD_HASH_HERE',
  'ADMIN'
);
```

Keep the password private and don't reuse an important personal password. Do not run the old default admin seed.

## 3. Configure the repository

The deployment-ready root `Dockerfile` uses PHP 8.3 + Apache and installs `pdo_pgsql`, `curl`, and `fileinfo`. Apache serves `public/` as the document root and listens on Render's runtime `PORT`.

Before pushing:

```bash
git status --short
git check-ignore -v .env storage/firebase-service-account.json
```

The deployment archive does not include `.env` or the Firebase service-account file. Never commit them. If the original repository has either file tracked, remove it from Git tracking, rotate the exposed credentials, and consider cleaning Git history.

Commit and push the deployment files:

```bash
git add Dockerfile docker/render-entrypoint.sh src/Core/Database.php public/index.php .env.example .gitignore .dockerignore database/seed.sql DEPLOY_RENDER_SUPABASE.md
git commit -m "Prepare SupportAssist for Render and Supabase"
git push origin main
```

If GitHub already contains your project, don't run `git init` again; commit to the existing repository.

## 4. Configure Render

1. Go to <https://dashboard.render.com/>.
2. Select **New → Web Service** and connect the GitHub repository.
3. Choose **Docker** as the runtime.
4. Set Dockerfile path to `./Dockerfile` and use branch `main`.
5. Choose the Free instance type.
6. In **Environment**, add the variables below.
7. In **Secret Files**, add the Supabase root CA certificate with the absolute path `/etc/secrets/supabase-prod-ca.crt`.
8. If Firebase push notifications are required, add the replacement service-account JSON as a separate Secret File at `/etc/secrets/firebase-service-account.json`. Do not put its contents in an environment variable or in Git.
9. Deploy and review build/runtime logs.

### Required Render environment variables

| Key | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://YOUR-RENDER-SERVICE.onrender.com` |
| `APP_CORS_ORIGINS` | Comma-separated exact frontend origins, e.g. `https://your-frontend.example` (no trailing slash) |
| `DB_HOST` | Supabase Session Pooler hostname from Connect |
| `DB_PORT` | `5432` |
| `DB_DATABASE` | `postgres` |
| `DB_USERNAME` | Exact username shown for the Session Pooler |
| `DB_PASSWORD` | The current Supabase database password |
| `DB_SSLMODE` | `verify-full` |
| `DB_SSLROOTCERT` | `/etc/secrets/supabase-prod-ca.crt` |
| `JWT_SECRET` | A newly generated random secret (see below) |
| `JWT_ACCESS_TTL_SECONDS` | `3600` |
| `JWT_REFRESH_TTL_SECONDS` | `1209600` |
| `UPLOAD_DIR` | `storage/uploads` |
| `UPLOAD_MAX_BYTES` | `5242880` |
| `RATE_LIMIT_MAX_REQUESTS` | `60` |
| `RATE_LIMIT_WINDOW_SECONDS` | `60` |

Generate a JWT secret locally with PHP:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Paste the output into Render as `JWT_SECRET`. Do not commit it. `APP_CORS_ORIGINS` must list the exact origin(s) used by your web frontend. Native mobile requests that omit an Origin header are not blocked by CORS.

For Firebase, if enabled, set:

- `FCM_PROJECT_ID` to the Firebase project ID.
- `FCM_SERVICE_ACCOUNT_PATH` to `/etc/secrets/firebase-service-account.json`.

If you do not need push notifications yet, leave `FCM_PROJECT_ID` unset.

## 5. Verify deployment

Render assigns a URL like `https://YOUR-SERVICE.onrender.com`. Test the health endpoint:

```bash
curl -i https://YOUR-SERVICE.onrender.com/health
```

Expected response: HTTP 200 and `{"status":"ok"}`.

Then test a public route. Most API routes require a valid bearer token, so `/api/categories` may return an authentication error until you log in. Use the login endpoint to obtain a token after the administrator exists:

```bash
curl -i -X POST https://YOUR-SERVICE.onrender.com/api/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"YOUR_ADMIN_EMAIL","password":"YOUR_ADMIN_PASSWORD"}'
```

Use the actual request fields expected by your frontend/client if they differ. Do not put real passwords in shell history on shared machines.

If deployment fails:

- **`could not find driver`**: verify the image build completed and `pdo_pgsql` was installed.
- **TLS certificate error**: verify the CA secret-file path, file contents, pooler hostname, and `DB_SSLMODE=verify-full`. Do not turn off certificate verification to hide the error.
- **Connection refused / timeout**: verify the Session Pooler host and port `5432`, username, password, and project status.
- **404 on API routes**: check Render logs and confirm `public/.htaccess` is copied and Apache rewrite is enabled.
- **500 on login**: inspect Render logs without sharing secrets; check schema, JWT secret, and DB connection.
- **CORS error**: set `APP_CORS_ORIGINS` to the exact frontend origin including scheme and port if applicable, without a trailing slash.

## 6. Free-tier limitations and file uploads

- Render's free web service can sleep when idle and cold-start on the next request.
- Supabase Free has storage and compute limits and may pause inactive projects. Check the current provider pricing/limits before deployment.
- Render's local filesystem is ephemeral. `storage/uploads` is available for runtime writes, but ticket attachments can be lost during redeploy/replacement. Move attachments to durable object storage before using this for important tickets.
- The current application returns attachment URLs under `/storage/uploads/`. These are publicly served by the app; do not use them for sensitive documents without changing the design to private object storage and authorization/signed URLs.
- The file-based rate limiter is best-effort and is not shared between multiple instances. It can reset after redeploys.
- Password reset is intentionally disabled in production until a real email provider is integrated; the local development flow logs tokens and must not be used with real accounts.
- Free tiers are suitable for demos and learning, not guaranteed production uptime or backup guarantees.
