# SupportAssist image uploads and retrieval

## Image processing rules

- Ticket attachment: normalized to a **1280 × 960 WebP** canvas. The complete source image is fitted inside the canvas without cropping; white padding is added where aspect ratios differ.
- Ticket thumbnail: **320 × 240 WebP**, generated during the same upload.
- Profile picture: center-cropped to **400 × 400 WebP**.
- Profile thumbnail: center-cropped to **96 × 96 WebP**.
- Accepted input MIME types remain JPEG, PNG, WebP, and GIF. GIF uploads are converted to a static WebP image (first frame).
- The original uploaded file is not retained; processed WebP variants are stored.

## Upload endpoints

All endpoints below require `Authorization: Bearer <access-token>`.

### Create ticket with an attachment

`POST /api/tickets` — `multipart/form-data`

Required text fields: `subject`, `description`. Optional image field: `attachment` (file). Other existing ticket fields remain supported.

Successful response includes:

```json
{
  "attachmentUrl": "/storage/uploads/YYYY/MM/<id>.webp",
  "attachmentThumbnailUrl": "/storage/uploads/YYYY/MM/thumbnails/<id>.webp"
}
```

If no attachment is supplied, both values are `null`.

### Upload a profile picture

`POST /api/profile/avatar` — `multipart/form-data`, file field `avatar`.

Successful response includes `data.avatarUrl` and `data.avatarThumbnailUrl`.

### Update profile and optionally upload a picture

`PATCH /api/profile` — `multipart/form-data` (or the API's existing supported request format). Fields `name`, `phone`, and optional file field `avatar`.

## Retrieval endpoints

All API endpoints below require `Authorization: Bearer <access-token>`:

- `GET /api/tickets` — list of tickets, each includes `attachmentUrl` and `attachmentThumbnailUrl`.
- `GET /api/tickets/recent` — recent tickets with both image URLs.
- `GET /api/tickets/{id}` — ticket details with both image URLs.
- `GET /api/profile` — current user's profile with `avatarUrl` and `avatarThumbnailUrl`.

Image URLs are static URLs served by Nginx and do not require a token:

- `GET /storage/uploads/YYYY/MM/<id>.webp` — full ticket attachment.
- `GET /storage/uploads/YYYY/MM/thumbnails/<id>.webp` — ticket thumbnail.
- `GET /storage/uploads/profiles/YYYY/MM/<id>.webp` — full profile image.
- `GET /storage/uploads/profiles/YYYY/MM/thumbs-<id>.webp` — profile thumbnail.

Use the API's base URL before a relative image URL, e.g. `http://192.168.1.105:8080` + `/storage/uploads/...`. On Android, use the laptop's LAN IP rather than `localhost` when accessing from the phone.

## Where files are stored

Docker Compose mounts the project's `./storage` directory into the PHP container and mounts the project directory into Nginx. Thus processed images are persisted on the laptop in:

- `storage/uploads/YYYY/MM/` for full ticket images
- `storage/uploads/YYYY/MM/thumbnails/` for ticket thumbnails
- `storage/uploads/profiles/YYYY/MM/` for profile images and their `thumbs-` variants

## Apply to an existing database

Run the new migration once against the existing database. From the project root in PowerShell:

```powershell
Get-Content .\database\migrations\006_add_image_variants.sql | docker compose exec -T db psql -U postgres -d supportassist
```

Then rebuild the PHP image so GD/WebP support and upload limits are installed:

```powershell
docker compose up -d --build app
```

Verify image processing support:

```powershell
docker compose exec app php -r "var_export(extension_loaded('gd') && function_exists('imagewebp'));"
```

Expected output: `true`.

## Notes

- New uploads are normalized. Existing files are not automatically reprocessed, and existing database rows will have null thumbnail URLs until the image is re-uploaded or separately backfilled.
- Static image files are publicly readable to preserve compatibility with direct browser `<img>` loads. Do not store sensitive/private material at these URLs without adding authenticated image-serving routes and ticket-level access checks.
- Do not run `docker compose down -v`; it can delete the database volume.
