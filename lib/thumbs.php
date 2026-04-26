<?php
// lib/thumbs.php — image thumbnail generation. GD for JPEG/PNG/WebP, Imagick for HEIC.

const THUMB_WIDTH   = 400;
const THUMB_QUALITY = 80;

function thumbs_can_heic(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    if (!extension_loaded('imagick')) return $cached = false;
    try {
        $cached = !empty(Imagick::queryFormats('HEIC'))
               || !empty(Imagick::queryFormats('HEIF'));
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

function strip_exif_available(): bool {
    return extension_loaded('imagick');
}

/**
 * Bake EXIF orientation into the pixels and remove all metadata
 * (including GPS) from an image file in place. JPEG/HEIC/HEIF only;
 * other types are no-ops. Requires Imagick.
 *
 * Returns true on a successful strip, false on skip or failure.
 */
function strip_exif(string $path, string $mime): bool {
    static $supported = ['image/jpeg', 'image/heic', 'image/heif'];
    if (!in_array($mime, $supported, true)) return false;
    if (!strip_exif_available()) {
        @file_put_contents(
            DATA_DIR . '/upload_errors.log',
            date('Y-m-d H:i:s') . " exif strip skipped (no imagick): $path\n",
            FILE_APPEND
        );
        return false;
    }
    try {
        $im = new Imagick($path);
        if (method_exists($im, 'autoOrient')) $im->autoOrient();
        $im->stripImage();
        $ok = $im->writeImage($path);
        $im->clear();
        return (bool)$ok;
    } catch (Throwable $e) {
        @file_put_contents(
            DATA_DIR . '/upload_errors.log',
            date('Y-m-d H:i:s') . " exif strip fail $path: " . $e->getMessage() . "\n",
            FILE_APPEND
        );
        return false;
    }
}

/**
 * Build the thumbnail filesystem path for a given upload.
 * Strips the original extension and appends .jpg, so HEIC/PNG/MP4 all
 * share the same thumb name space without collisions.
 */
function thumb_path_for(string $upload_dir, string $filename): string {
    $base = pathinfo($filename, PATHINFO_FILENAME);
    return $upload_dir . '/thumbs/' . $base . '.jpg';
}
function thumb_filename_for(string $filename): string {
    return pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
}

/** Returns true if a thumb was written to $dest, false on skip/failure. */
function generate_thumb(string $src, string $dest, string $mime): bool {
    if (!is_file($src)) return false;
    $dest_dir = dirname($dest);
    if (!is_dir($dest_dir)) @mkdir($dest_dir, 0775, true);

    try {
        if ($mime === 'image/heic' || $mime === 'image/heif') {
            return thumbs_can_heic() ? thumb_via_imagick($src, $dest) : false;
        }
        if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return thumb_via_gd($src, $dest, $mime);
        }
        return false;
    } catch (Throwable $e) {
        @file_put_contents(
            DATA_DIR . '/upload_errors.log',
            date('Y-m-d H:i:s') . " thumb fail $src: " . $e->getMessage() . "\n",
            FILE_APPEND
        );
        return false;
    }
}

function thumb_via_imagick(string $src, string $dest): bool {
    $im = new Imagick($src);
    if (method_exists($im, 'autoOrient')) $im->autoOrient();
    $im->setImageFormat('jpeg');
    $im->thumbnailImage(THUMB_WIDTH, 0);
    $im->setImageCompressionQuality(THUMB_QUALITY);
    $ok = $im->writeImage($dest);
    $im->clear();
    return (bool)$ok;
}

function thumb_via_gd(string $src, string $dest, string $mime): bool {
    $info = @getimagesize($src);
    if (!$info) return false;
    [$w, $h] = $info;

    $img = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($src),
        'image/png'  => @imagecreatefrompng($src),
        'image/webp' => @imagecreatefromwebp($src),
        default      => null,
    };
    if (!$img) return false;

    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        $orient = (int)($exif['Orientation'] ?? 0);
        if ($orient === 3) $img = imagerotate($img, 180, 0);
        elseif ($orient === 6) { $img = imagerotate($img, -90, 0); [$w, $h] = [$h, $w]; }
        elseif ($orient === 8) { $img = imagerotate($img,  90, 0); [$w, $h] = [$h, $w]; }
    }

    if ($w <= THUMB_WIDTH) {
        $tw = $w; $th = $h;
    } else {
        $tw = THUMB_WIDTH;
        $th = (int)round($h * (THUMB_WIDTH / $w));
    }

    $thumb = imagecreatetruecolor($tw, $th);
    imagecopyresampled($thumb, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
    $ok = imagejpeg($thumb, $dest, THUMB_QUALITY);
    imagedestroy($thumb);
    imagedestroy($img);
    return (bool)$ok;
}
