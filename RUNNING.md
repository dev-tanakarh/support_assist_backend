# Starting the Backend

This project is now configured and ready to run. Use one of these methods:

## Option 1: PowerShell (Recommended)

```powershell
.\start.ps1
```

Or from anywhere:
```powershell
cd c:\Users\DELL\Downloads\supportassist-backend\supportassist-backend
.\start.ps1
```

## Option 2: Batch File (Double-click)

Simply double-click `start.bat` in the project folder.

## Option 3: Manual Command

```powershell
$env:PGPASSWORD = "tanaka"
C:\Users\DELL\Downloads\php-8.5.10-nts-Win32-vs17-x64\php.exe -S localhost:8080 -t public
```

---

## What's Running

Once started, the API is available at:
- **URL:** http://localhost:8080/api
- **Default Login:** admin@helpdesk.com / admin123

## Quick Test

```bash
curl -X POST http://localhost:8080/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@helpdesk.com","password":"admin123"}'
```

## Database

- **Type:** PostgreSQL 16
- **Host:** 127.0.0.1
- **Port:** 5432
- **Database:** supportassist
- **User:** postgres
- **Password:** tanaka

## Stopping the Server

Press `Ctrl+C` in the terminal.

---

## Common Issues

**"Database connection failed"**
- Ensure PostgreSQL is running
- Check `.env` has correct DB_PASSWORD

**"PHP not recognized"**
- The startup scripts assume PHP is at: `C:\Users\DELL\Downloads\php-8.5.10-nts-Win32-vs17-x64\php.exe`
- Update the path in `start.ps1` or `start.bat` if needed

**"JWT_SECRET is not configured"**
- Ensure `.env` file exists and has JWT_SECRET set

---

## Push Notifications (FCM)

1. Firebase Console → your project → ⚙️ Project Settings → Service Accounts →
   "Generate new private key". Save the downloaded JSON as
   `storage/firebase-service-account.json` (already gitignored).
2. In `.env`, set `FCM_PROJECT_ID` to your Firebase project id.
3. That's it — `Alert::create()` now pushes automatically whenever it fires,
   which covers status changes, assignments, and new-ticket alerts. Nothing
   else to wire up. If `FCM_PROJECT_ID` is blank, pushes silently no-op —
   safe to leave unset while developing.
4. Android side needs to call `POST /api/user/fcm-token` with
   `{"fcm_token": "<token from FirebaseMessaging.getInstance().getToken()>"}`
   after login and whenever `FirebaseMessagingService.onNewToken()` fires.

## Real-time updates without WebSockets

`GET /api/alerts/poll?since=<ISO8601>&timeout=25` long-polls: the request
stays open, checking for new alerts once a second, and returns the moment
something shows up (or after `timeout` seconds with nothing). The client
loop is: call it, take whatever `alerts` array comes back (may be empty),
then immediately call again using the response's `serverTime` as the next
`since`. That gives near-instant updates on both the web app and mobile
without a WebSocket server — appropriate for this stack, since Socket.io
needs a persistent Node process and this backend is plain PHP-FPM.

**This is not free at scale.** Every open poll holds a PHP-FPM worker for up
to `timeout` seconds — it's not one lightweight connection like a real
WebSocket, it's a parked worker. If ~100 of your 500 hospital staff have the
web app open at once, that's ~100 workers tied up just waiting. Check (and
raise) `pm.max_children` in whatever php-fpm pool config you deploy with —
the Alpine image's default is low, sized for quick request/response traffic,
not long-held connections. If usage ever grows well past a few hundred
concurrent pollers, that's the point to actually stand up a small
Node/Swoole WebSocket service for this one endpoint rather than scaling
PHP-FPM workers — long-polling stops being the right tool past that point.

---

*(Unrelated, but worth fixing separately: this file has a real DB password
in it above — "Password: tanaka" — and it looks like it's committed to the
repo. Rotate that password and don't put real credentials in a markdown
file that goes into version control, even for a local dev DB.)*
