# SupportAssist Helpdesk — Backend API

A RESTful JSON API for a helpdesk ticketing system, built to serve **both**
a Java Android app and an HTML/CSS/JS web frontend from one backend.

- **Language:** PHP 8.3, pure — no Composer, no framework
- **Database:** PostgreSQL 16
- **Auth:** JWT (access + refresh tokens), hand-rolled HS256
- **Deployment:** Docker Compose (nginx + php-fpm + postgres), or any
  Apache/nginx + PHP-FPM host

---

## Quick start (Docker)

```bash
cp .env.example .env
# edit .env — at minimum, set a real JWT_SECRET (see comment in the file)

docker compose up --build
```

The API is now live at `http://localhost:8080/api`. The `db` container
runs `database/schema.sql` and `database/seed.sql` automatically on first
boot (via Postgres's `docker-entrypoint-initdb.d` mechanism), so you get
the ticket categories and a default admin account for free:

```
POST http://localhost:8080/api/login
{ "email": "admin@helpdesk.com", "password": "admin123" }
```

**Change that password before deploying anywhere real.** Regenerate the
hash with `php database/generate_admin_hash.php "newPassword"` and update
`database/seed.sql`.

## Quick start (without Docker)

1. Create a PostgreSQL database, then run the schema and seed:
   ```bash
   psql -U postgres -d supportassist -f database/schema.sql
   psql -U postgres -d supportassist -f database/seed.sql
   ```
2. Copy `.env.example` to `.env` and fill in your DB credentials + a real
   `JWT_SECRET`.
3. Point your webserver's document root at `public/`. For a quick local
   test, PHP's built-in server works fine:
   ```bash
   php -S localhost:8080 -t public
   ```
   (Note: the built-in server doesn't run `.htaccess`, but every route
   already goes through `public/index.php` so this still works for API
   testing — you just won't get pretty error pages for missing files.)

---

## Architecture decisions (and why)

These are the kinds of things you should be ready to explain in an
interview about this project — a few sentences on each, honestly, is more
convincing than a long feature list.

**No Composer, no framework.** Everything here — the router, the JWT
handling, the .env parser — is maybe 400 lines of PHP you can read
top-to-bottom in twenty minutes. For a learning project, that's a feature:
you can explain *exactly* what every line does, instead of "Laravel
handles that." The tradeoff is real, though — in a team/production
setting you'd use Laravel or Symfony, because reinventing routing and
auth means reinventing their edge cases and bugs too. Say this out loud
if asked; don't pretend hand-rolling it was the "better" choice, just the
more instructive one for a solo school project.

**Native PostgreSQL ENUMs for status/priority/role**, per the original
spec. The tradeoff worth knowing: adding a new enum value later is a
migration (`ALTER TYPE ... ADD VALUE`), whereas a `status_id` FK to a
lookup table only needs an `INSERT`. For a fixed, small set of statuses
this is fine; for something that changes often, prefer the lookup table.

**Role checks live in the model layer, not just the controller.**
`Ticket::listFor()` and `Ticket::assertCanView()`-style checks filter by
the caller's role directly in the query/logic — an END_USER's SQL query
is scoped to `user_id = :them` at the query level, not just "checked
after the fact." That means there's no code path where a bug in a
controller could leak someone else's tickets.

**Rate limiting is file-based**, bucketed per-minute per user/IP. That's
fine for one PHP-FPM box. At real scale you'd swap it for Redis
(`INCR`+`EXPIRE`) — the middleware interface (`RateLimitMiddleware`)
is the seam where that swap would happen without touching a single
controller.

**JWT access + refresh token pair.** Access tokens are short-lived
(1 hour default) and stateless. Refresh tokens are opaque random strings,
stored server-side as a SHA-256 hash (never the raw token) in
`refresh_tokens`, and rotated on every use — the old one is revoked the
moment a new one is issued. This limits the blast radius of a leaked
refresh token.

**Full-text search on tickets** uses Postgres's built-in `tsvector` +
`GIN` index (`GET /api/tickets?q=printer`) instead of pulling in
Elasticsearch for a project this size — using the database you already
have well is itself worth demonstrating.

---

## What would change at real scale (interview-ready honesty)

Be upfront about these if asked — naming your own project's limits is a
strong signal, not a weak one:

- File uploads are stored on local disk; a horizontally-scaled deployment
  needs S3/object storage instead.
- Rate limiting is per-box; needs Redis behind a load balancer.
- No background job queue — alerts are written synchronously inside the
  request. Fine at this scale; a real notification system (push/email)
  would move this to a queue (e.g. a `jobs` table + a worker, or
  RabbitMQ/SQS).
- No automated test suite yet. If you extend this, start with PHPUnit
  tests around `Ticket::listFor()`'s role-scoping logic first — that's
  the code where a bug is a security bug.
- No API versioning prefix (`/api/v1/...`). Fine for a school project
  with one consumer you control; add it before any external client
  depends on this.

---

## Authentication

Every protected endpoint expects:
```
Authorization: Bearer <accessToken>
```

Access tokens expire in 1 hour by default (`JWT_ACCESS_TTL_SECONDS`).
Use `POST /api/refresh` with a valid `refreshToken` to get a new pair
without asking the user to log in again.

---

## Endpoints

All responses share this envelope:
```json
{ "success": true, "data": { ... }, "message": "optional" }
{ "success": false, "message": "...", "errors": { "field": "..." } }
```

### Auth

**POST /api/signup**
```json
// Request
{ "name": "Tapiwa Moyo", "email": "tapiwa@example.com", "phone": "+260971234567", "password": "SecurePass1" }
```
```json
// 201 Response
{
  "success": true,
  "data": {
    "accessToken": "eyJ...",
    "refreshToken": "8f2c...",
    "expiresIn": 3600,
    "user": {
      "id": "b3f1...-uuid",
      "name": "Tapiwa Moyo",
      "email": "tapiwa@example.com",
      "phone": "+260971234567",
      "userType": "END_USER",
      "isActive": true,
      "createdAt": "2026-09-07T10:00:00+00:00"
    }
  }
}
```

**POST /api/login** — same request/response shape as signup, body is `{ "email", "password" }`.

**POST /api/refresh**
```json
{ "refreshToken": "8f2c..." }
```
Returns a fresh `accessToken` + `refreshToken` pair (old refresh token is revoked).

### Profile

**GET /api/profile** → the `user` object shown above.

**PATCH /api/profile**
```json
{ "name": "New Name", "phone": "+260970000000" }
```

### Categories

**GET /api/categories**
```json
{ "success": true, "data": [ { "id": 1, "name": "Network Issues" }, { "id": 2, "name": "Software Issues" } ] }
```

### Tickets

**GET /api/tickets** — query params: `status`, `priority`, `category_id`,
`q` (full-text search), `unassigned=true` (technicians browsing the
unclaimed pool), `page`, `per_page`.

```json
{
  "success": true,
  "data": {
    "tickets": [ /* array of Ticket objects, shape below */ ],
    "pagination": { "page": 1, "perPage": 20, "total": 42, "totalPages": 3 }
  }
}
```

**Ticket object shape** (matches what `Ticket.java` should deserialize into — camelCase, nested objects for related entities):
```json
{
  "id": "TKT-2026-0007",
  "subject": "VPN keeps disconnecting",
  "description": "Drops every ~10 minutes on the office network.",
  "status": "OPEN",
  "priority": "HIGH",
  "department": "Finance",
  "deviceType": "Laptop",
  "attachmentUrl": "/storage/uploads/2026/09/ab12cd34.jpg",
  "category": { "id": 1, "name": "Network Issues" },
  "reporter": { "id": "uuid", "name": "Tapiwa Moyo", "email": "tapiwa@example.com" },
  "assignedTechnician": null,
  "createdAt": "2026-09-07T09:12:00+00:00",
  "updatedAt": "2026-09-07T09:12:00+00:00",
  "dueAt": "2026-09-07T13:12:00+00:00",
  "resolvedAt": null,
  "isOverdue": false,
  "timeAgo": "10 mins ago",
  "statusHistory": [ /* only present on GET /api/tickets/{id} */
    { "oldStatus": null, "newStatus": "OPEN", "changedBy": "Tapiwa Moyo", "note": "Ticket created", "changedAt": "2026-09-07T09:12:00+00:00" }
  ]
}
```

**GET /api/tickets/recent** — `?limit=4` (default 4). Returns an array of Ticket objects (no pagination wrapper).

**GET /api/tickets/{id}** — full Ticket object, including `statusHistory`.

**POST /api/tickets** — `multipart/form-data` if attaching an image, otherwise plain JSON.
```
subject: string (required)
description: string (required)
category_id: int
priority: HIGH | MEDIUM | LOW (default MEDIUM)
department: string
device_type: string
attachment: file (image, multipart only)
```
`due_at` is set automatically from priority (HIGH: 4h, MEDIUM: 24h, LOW: 72h SLA).

**PATCH /api/tickets/{id}/status** — technician (must be assigned to it) or admin.
```json
{ "status": "IN_PROGRESS", "note": "optional" }
```
Fires a `STATUS_CHANGE` alert to the reporting user.

**POST /api/tickets/{id}/assign** — admin only.
```json
{ "technicianId": "uuid" }
```
Fires an `ASSIGNMENT` alert to both the user and the technician.

**POST /api/tickets/{id}/accept** — technician only, for an unassigned ticket. Self-assigns and moves status to `IN_PROGRESS`. Fires a `TECH_ACCEPTED` alert to the user.

**POST /api/tickets/{id}/review** — end-user only, ticket must be `RESOLVED` or `CLOSED`.
```json
{ "rating": 5, "comment": "Fixed quickly, thanks!" }
```

### Alerts

**GET /api/alerts** — `?page=&per_page=`
```json
{
  "success": true,
  "data": [
    {
      "id": 14,
      "title": "Ticket TKT-2026-0007 is now IN_PROGRESS",
      "message": "Your ticket \"VPN keeps disconnecting\" changed from OPEN to IN_PROGRESS.",
      "type": "STATUS_CHANGE",
      "colorCode": "#2196F3",
      "ticketId": "TKT-2026-0007",
      "isRead": false,
      "timeAgo": "2 mins ago",
      "createdAt": "2026-09-07T09:20:00+00:00"
    }
  ]
}
```
`type` is one of `STATUS_CHANGE | ASSIGNMENT | TECH_ACCEPTED | NEW_TICKET | REVIEW_RECEIVED | SYSTEM` — the app can map this to an icon; `colorCode` is provided so the client doesn't need its own mapping table.

**PATCH /api/alerts/{id}/read** — marks one alert read.

### Admin

**GET /api/admin/users** — `?type=END_USER|TECHNICIAN|ADMIN` (optional).

**POST /api/admin/technicians**
```json
{ "name": "Kudzai Banda", "email": "kudzai@helpdesk.com", "phone": "+260979999999", "password": "SecurePass1" }
```

**DELETE /api/admin/technicians/{id}**

**POST /api/admin/alerts** — broadcast or targeted.
```json
{ "title": "Scheduled maintenance", "message": "System will be down 10pm-11pm.", "userId": null }
```
Omit `userId` (or send `null`) to broadcast to every END_USER.

**GET /api/admin/dashboard**
```json
{
  "success": true,
  "data": {
    "byStatus": [ { "status": "OPEN", "count": "12" }, { "status": "RESOLVED", "count": "30" } ],
    "byPriority": [ { "priority": "HIGH", "count": "5" } ],
    "avgResolutionSeconds": 41230.5,
    "overdueCount": 3,
    "topTechnicians": [ { "name": "Kudzai Banda", "resolved_count": "14" } ]
  }
}
```

---

## Role permission matrix

| Action                              | END_USER | TECHNICIAN         | ADMIN |
|--------------------------------------|:--------:|:-------------------:|:-----:|
| Create ticket                        | ✅        | —                    | —     |
| View own tickets                     | ✅        | —                    | —     |
| View assigned tickets                | —        | ✅                    | ✅ (all) |
| Browse unassigned ticket pool        | —        | ✅ (`?unassigned=true`) | ✅  |
| Change ticket status                 | —        | ✅ (own assignments)  | ✅    |
| Assign technician to ticket          | —        | —                    | ✅    |
| Accept an unclaimed ticket           | —        | ✅                    | —     |
| Leave a review                       | ✅ (own resolved tickets) | —      | —     |
| Manage technician accounts           | —        | —                    | ✅    |
| Broadcast alerts                     | —        | —                    | ✅    |
| View dashboard analytics             | —        | —                    | ✅    |

---

## Project structure

```
public/index.php          Front controller — CORS, error handling, dispatch
src/routes.php             All routes in one place
src/Core/                  Router, Request, Response, Database, Jwt, Env, FileUpload
src/Middleware/            AuthMiddleware, RoleMiddleware, RateLimitMiddleware
src/Models/                User, Ticket, Alert — query + JSON-shaping logic
src/Controllers/           One controller per resource
database/schema.sql        Tables, enums, triggers, indexes
database/seed.sql          Categories + default admin
docker/                    Dockerfile + nginx.conf
docker-compose.yml         nginx + php-fpm + postgres, one command
```
