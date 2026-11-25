<?php

declare(strict_types=1);

namespace Lou\Admin;

use Lou\LouException;
use Lou\Support\Fs;
use Lou\Support\Str;

/**
 * Validates uploaded images and re-encodes them through GD. Re-encoding strips EXIF/GPS data and any
 * payload hidden in the file. Output: WebP (or JPEG/PNG without WebP support), max width configurable.
 */
final class ImageProcessor
{
    private const MAX_BYTES = 15 * 1024 * 1024;
    private const MAX_PIXELS = 40_000_000;

    public function __construct(private readonly int $maxWidth = 1920, private readonly int $quality = 82)
    {
        if (!extension_loaded('gd')) {
            throw new LouException('The PHP gd extension is required for image uploads');
        }
    }

    /**
     * @param string $tmpFile  the uploaded file
     * @param string $origName the client's file name (only used for the slug)
     * @param string $siteDir  site/ directory; files go to site/assets/uploads/YYYY/
     * @return array{src: string, width: int, height: int}
     */
    public function process(string $tmpFile, string $origName, string $siteDir): array
    {
        if (!is_file($tmpFile) || filesize($tmpFile) === 0) {
            throw new LouException('Upload failed');
        }
        if (filesize($tmpFile) > self::MAX_BYTES) {
            throw new LouException('Image is too large (max 15 MB)');
        }
        $info = @getimagesize($tmpFile);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            throw new LouException('Only JPEG, PNG, GIF and WebP images are allowed');
        }
        [$w, $h] = $info;
        if ($w * $h > self::MAX_PIXELS) {
            throw new LouException('Image dimensions are too large');
        }
        $img = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpFile),
            IMAGETYPE_PNG  => @imagecreatefrompng($tmpFile),
            IMAGETYPE_GIF  => @imagecreatefromgif($tmpFile),
            IMAGETYPE_WEBP => @imagecreatefromwebp($tmpFile),
        };
        if (!$img) {
            throw new LouException('The image could not be read');
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            $img = $this->orient($img, $tmpFile);
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        imagealphablending($img, false);
        imagesavealpha($img, true);

        if (imagesx($img) > $this->maxWidth) {
            $scaled = imagescale($img, $this->maxWidth, -1, IMG_BICUBIC);
            if ($scaled) {
                imagedestroy($img);
                $img = $scaled;
                imagealphablending($img, false);
                imagesavealpha($img, true);
            }
        }
        $w = imagesx($img);
        $h = imagesy($img);

        $ext = function_exists('imagewebp') ? 'webp' : ($info[2] === IMAGETYPE_PNG ? 'png' : 'jpg');
        $rel = 'assets/uploads/' . gmdate('Y') . '/' . Str::slug(pathinfo($origName, PATHINFO_FILENAME), 'image')
            . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
        $dest = $siteDir . '/' . $rel;
        Fs::ensureDir(dirname($dest));
        $ok = match ($ext) {
            'webp' => imagewebp($img, $dest, $this->quality),
            'png'  => imagepng($img, $dest, 9),
            'jpg'  => imagejpeg($img, $dest, 85),
        };
        imagedestroy($img);
        if (!$ok) {
            throw new LouException('Saving the image failed');
        }
        return ['src' => '/' . $rel, 'width' => $w, 'height' => $h];
    }

    private function orient(\GdImage $img, string $file): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }
        $o = (int) (@exif_read_data($file)['Orientation'] ?? 1);
        $rotated = match ($o) {
            3       => imagerotate($img, 180, 0),
            6       => imagerotate($img, -90, 0),
            8       => imagerotate($img, 90, 0),
            default => null,
        };
        return $rotated ?: $img;
    }
}
