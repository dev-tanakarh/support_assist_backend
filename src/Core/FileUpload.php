<?php

namespace App\Core;

use RuntimeException;

/**
 * Validates, normalizes and stores images on the host-mounted storage/uploads
 * directory. Ticket images use a fixed 4:3 canvas; avatars use a square crop.
 */
class FileUpload
{
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const TICKET_WIDTH = 1280;
    private const TICKET_HEIGHT = 960;
    private const THUMB_WIDTH = 320;
    private const THUMB_HEIGHT = 240;
    private const AVATAR_SIZE = 400;
    private const AVATAR_THUMB_SIZE = 96;

    /** @return array{url:string,thumbnailUrl:string}|null */
    public static function storeTicketAttachment(?array $file): ?array
    {
        $source = self::loadUploadedImage($file);
        if ($source === null) {
            return null;
        }

        [$image, $width, $height] = $source;
        $baseDir = self::baseDir();
        $subDir = date('Y/m');
        $dir = $baseDir . '/' . $subDir;
        $thumbDir = $dir . '/thumbnails';
        self::ensureDirectory($thumbDir);

        $filename = bin2hex(random_bytes(16)) . '.webp';
        $fullPath = $dir . '/' . $filename;
        $thumbPath = $thumbDir . '/' . $filename;

        try {
            $normalized = self::containOnCanvas($image, $width, $height, self::TICKET_WIDTH, self::TICKET_HEIGHT);
            if (!imagewebp($normalized, $fullPath, 82)) {
                throw new RuntimeException('Could not save the ticket attachment.');
            }
            imagedestroy($normalized);

            $thumb = self::containOnCanvas($image, $width, $height, self::THUMB_WIDTH, self::THUMB_HEIGHT);
            if (!imagewebp($thumb, $thumbPath, 78)) {
                imagedestroy($thumb);
                @unlink($fullPath);
                throw new RuntimeException('Could not save the ticket attachment thumbnail.');
            }
            imagedestroy($thumb);
        } finally {
            imagedestroy($image);
        }

        return [
            'url' => self::publicUrl($subDir . '/' . $filename),
            'thumbnailUrl' => self::publicUrl($subDir . '/thumbnails/' . $filename),
        ];
    }

    /** @return array{url:string,thumbnailUrl:string}|null */
    public static function storeProfileImage(?array $file): ?array
    {
        $source = self::loadUploadedImage($file);
        if ($source === null) {
            return null;
        }

        [$image, $width, $height] = $source;
        $baseDir = self::baseDir();
        $subDir = 'profiles/' . date('Y/m');
        $dir = $baseDir . '/' . $subDir;
        self::ensureDirectory($dir);

        $filename = bin2hex(random_bytes(16)) . '.webp';
        $fullPath = $dir . '/' . $filename;
        $thumbPath = $dir . '/thumbs-' . $filename;

        try {
            $avatar = self::centerCropSquare($image, $width, $height, self::AVATAR_SIZE);
            if (!imagewebp($avatar, $fullPath, 82)) {
                imagedestroy($avatar);
                throw new RuntimeException('Could not save the profile picture.');
            }
            imagedestroy($avatar);

            $thumb = self::centerCropSquare($image, $width, $height, self::AVATAR_THUMB_SIZE);
            if (!imagewebp($thumb, $thumbPath, 78)) {
                imagedestroy($thumb);
                @unlink($fullPath);
                throw new RuntimeException('Could not save the profile picture thumbnail.');
            }
            imagedestroy($thumb);
        } finally {
            imagedestroy($image);
        }

        return [
            'url' => self::publicUrl($subDir . '/' . $filename),
            'thumbnailUrl' => self::publicUrl($subDir . '/thumbs-' . $filename),
        ];
    }

    /** @return array{0:GdImage,1:int,2:int}|null */
    private static function loadUploadedImage(?array $file): ?array
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed (error code ' . ($file['error'] ?? 'unknown') . ').');
        }

        $maxBytes = (int) Env::get('UPLOAD_MAX_BYTES', 5242880);
        if (($file['size'] ?? 0) > $maxBytes) {
            throw new RuntimeException('File exceeds maximum size of ' . round($maxBytes / 1048576, 1) . 'MB.');
        }
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('The uploaded image is invalid.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new RuntimeException('Only JPEG, PNG, WEBP, or GIF images are allowed.');
        }

        $info = @getimagesize($file['tmp_name']);
        if (!$info || empty($info[0]) || empty($info[1])) {
            throw new RuntimeException('The uploaded file is not a valid image.');
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        // Avoid decoding unreasonable pixel dimensions in a small PHP container.
        if ($width > 12000 || $height > 12000 || ($width * $height) > 40000000) {
            throw new RuntimeException('Image dimensions are too large. Please upload a smaller image.');
        }
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            throw new RuntimeException('Image processing is unavailable: PHP GD with WebP support is required.');
        }

        $bytes = file_get_contents($file['tmp_name']);
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);
        if (!$image instanceof \GdImage) {
            throw new RuntimeException('Could not decode the uploaded image.');
        }
        return [$image, $width, $height];
    }

    /** Fixed-size canvas; fits the complete image and pads empty space in white. */
    private static function containOnCanvas(\GdImage $source, int $sourceW, int $sourceH, int $targetW, int $targetH): \GdImage
    {
        $canvas = imagecreatetruecolor($targetW, $targetH);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        $scale = min($targetW / $sourceW, $targetH / $sourceH);
        $drawW = max(1, (int) round($sourceW * $scale));
        $drawH = max(1, (int) round($sourceH * $scale));
        $x = (int) floor(($targetW - $drawW) / 2);
        $y = (int) floor(($targetH - $drawH) / 2);
        imagecopyresampled($canvas, $source, $x, $y, 0, 0, $drawW, $drawH, $sourceW, $sourceH);
        return $canvas;
    }

    /** Fixed square center crop, used for both profile images and avatar thumbnails. */
    private static function centerCropSquare(\GdImage $source, int $sourceW, int $sourceH, int $size): \GdImage
    {
        $side = min($sourceW, $sourceH);
        $srcX = (int) floor(($sourceW - $side) / 2);
        $srcY = (int) floor(($sourceH - $side) / 2);
        $canvas = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $source, 0, 0, $srcX, $srcY, $size, $size, $side, $side);
        return $canvas;
    }

    private static function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create image storage directory.');
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('Image storage directory is not writable.');
        }
    }

    private static function baseDir(): string
    {
        return dirname(__DIR__, 2) . '/' . trim((string) Env::get('UPLOAD_DIR', 'storage/uploads'), '/');
    }

    private static function publicUrl(string $relativePath): string
    {
        return '/storage/uploads/' . str_replace('\\', '/', $relativePath);
    }
}
