<?php
// lib/thumbs.php — upload image processing: EXIF/GPS strip + thumbnail from a
// single decode. GD for JPEG/PNG/WebP thumbs, Imagick for HEIC and stripping.

const THUMB_WIDTH   = 400;
const THUMB_QUALITY = 80;
const FULL_JPEG_QUALITY = 85;     // quality when transcoding HEIC → JPEG
const GD_MAX_PIXELS = 60000000;   // skip GD decode above ~60 MP — the pixel
                                  // buffer (plus imagerotate's copy) would
                                  // approach the 512M memory_limit

// Cap ImageMagick's appetite before any decode. Its pixel cache is native
// memory OUTSIDE PHP's memory_limit, and by default it spawns OpenMP threads
// per operation — several concurrent uploads on a shared host can exhaust the
// account's process/memory limits and take the whole site down.
function imagick_limits(): void {
    static $done = false;
    if ($done || !extension_loaded('imagick')) return;
    $done = true;
    try {
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_THREAD, 1);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP,    512 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_DISK,   1024 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME,   60);
    } catch (Throwable $e) { /* limits are best-effort */ }
}

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
 * Build the thumbnail filesystem path for a given upload.
 * Strips the original extension and appends .jpg, so HEIC/PNG/MP4 all
 * share the same thumb name space without collisions.
 */
function thumb_path_for(string $upload_dir, string $filename): string {
    return $upload_dir . '/thumbs/' . thumb_filename_for($filename);
}
function thumb_filename_for(string $filename): string {
    return pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
}

function thumbs_log(string $msg): void {
    @file_put_contents(
        DATA_DIR . '/upload_errors.log',
        date('Y-m-d H:i:s') . " $msg\n",
        FILE_APPEND
    );
}

/**
 * Process one uploaded image in place: bake EXIF orientation into the pixels,
 * strip all metadata (including GPS), transcode HEIC to JPEG, and write the
 * 400px thumbnail — all from ONE full-resolution decode. Never write HEIC
 * back out: HEIC encoding runs the x265 encoder and can peg a CPU core for
 * minutes per photo, which is what melts the shared host under event load.
 *
 * Returns ['filename' => possibly renamed, 'mime' => final mime,
 * 'has_thumb' => bool]. Videos and unknown types pass through untouched.
 */
function process_upload_image(string $upload_dir, string $fname, string $mime): array {
    $path = "$upload_dir/$fname";
    $out  = ['filename' => $fname, 'mime' => $mime, 'has_thumb' => false];
    if (!is_file($path)) return $out;

    $is_heic = in_array($mime, ['image/heic', 'image/heif'], true);
    $is_jpeg = $mime === 'image/jpeg';

    if (($is_heic || $is_jpeg) && !strip_exif_available()) {
        thumbs_log("exif strip skipped (no imagick): $path");
    }

    if (($is_heic && thumbs_can_heic()) || ($is_jpeg && strip_exif_available())) {
        try {
            imagick_limits();
            $im = new Imagick($path);
            if (method_exists($im, 'autoOrient')) $im->autoOrient();
            $im->stripImage();

            if ($is_heic) {
                // Store HEIC uploads as full-size JPEG: one fast encode,
                // browser-viewable for admins, and GPS-free.
                $new_fname = pathinfo($fname, PATHINFO_FILENAME) . '.jpg';
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(FULL_JPEG_QUALITY);
                if ($im->writeImage("$upload_dir/$new_fname")) {
                    @unlink($path);
                    $out['filename'] = $fname = $new_fname;
                    $out['mime']     = 'image/jpeg';
                    $path = "$upload_dir/$fname";
                }
            } else {
                $im->writeImage($path);
            }

            $thumb = thumb_path_for($upload_dir, $fname);
            if (!is_dir(dirname($thumb))) @mkdir(dirname($thumb), 0775, true);
            $im->setImageFormat('jpeg');
            $im->thumbnailImage(THUMB_WIDTH, 0);
            $im->setImageCompressionQuality(THUMB_QUALITY);
            $out['has_thumb'] = (bool)$im->writeImage($thumb);
            $im->clear();
            return $out;
        } catch (Throwable $e) {
            thumbs_log("image process fail $path: " . $e->getMessage());
            // JPEG can still get a GD thumb below; HEIC cannot.
        }
    }

    if (in_array($out['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
        try {
            $thumb = thumb_path_for($upload_dir, $out['filename']);
            if (!is_dir(dirname($thumb))) @mkdir(dirname($thumb), 0775, true);
            $out['has_thumb'] = thumb_via_gd($path, $thumb, $out['mime']);
        } catch (Throwable $e) {
            thumbs_log("thumb fail $path: " . $e->getMessage());
        }
    }
    return $out;
}

function thumb_via_gd(string $src, string $dest, string $mime): bool {
    $info = @getimagesize($src);
    if (!$info) return false;
    [$w, $h] = $info;
    if ($w * $h > GD_MAX_PIXELS) return false;

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
