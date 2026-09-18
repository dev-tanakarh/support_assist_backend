<?php

namespace App\Core;

use RuntimeException;

class FileUpload
{
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Validates and stores an uploaded image, returns a public relative URL
     * (e.g. /storage/uploads/2026/09/abc123.jpg) or null if no file given.
     *
     * @throws RuntimeException on validation failure
     */
    public static function storeImage(?array $file): ?string
    {
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed (error code ' . $file['error'] . ').');
        }

        $maxBytes = (int) Env::get('UPLOAD_MAX_BYTES', 5242880);
        if ($file['size'] > $maxBytes) {
            throw new RuntimeException('File exceeds maximum size of ' . round($maxBytes / 1048576, 1) . 'MB.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new RuntimeException('Only JPEG, PNG, WEBP, or GIF images are allowed.');
        }

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        };

        $baseDir = dirname(__DIR__, 2) . '/' . trim(Env::get('UPLOAD_DIR', 'storage/uploads'), '/');
        $subDir = date('Y/m');
        $targetDir = "$baseDir/$subDir";
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Could not create upload directory.');
        }

        $filename = bin2hex(random_bytes(16)) . ".$ext";
        $destination = "$targetDir/$filename";

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Could not save uploaded file.');
        }

        return "/storage/uploads/$subDir/$filename";
    }
}
