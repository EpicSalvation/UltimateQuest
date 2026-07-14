<?php
// api/file.php — admin-gated full-file server. Supports HTTP Range requests
// so videos can be streamed/seeked (mobile Safari refuses to play <video>
// from endpoints without Range support) and the admin modal doesn't have to
// pull whole files to scrub.
require_once __DIR__.'/../lib/auth.php';
require_admin();

$team = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['team'] ?? '');
$task = intval($_GET['task'] ?? 0);
$file = basename($_GET['f'] ?? '');

$path = DATA_DIR . "/uploads/$team/$task/$file";
if ($team === '' || $task <= 0 || $file === '' || !is_file($path)) {
    http_response_code(404); exit('Not found');
}

$size = filesize($path);
$mime = mime_content_type($path) ?: 'application/octet-stream';

header("Content-Type: $mime");
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . rawurlencode($file) . '"');
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600');

$start = 0;
$end   = $size - 1;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') {
        $start = (int)$m[1];
        if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
    } elseif ($m[2] !== '') {
        // Suffix range: last N bytes.
        $start = max(0, $size - (int)$m[2]);
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));

if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;

$fh = fopen($path, 'rb');
if (!$fh) { http_response_code(500); exit; }
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh)) {
    $chunk = fread($fh, min(1024 * 512, $left));
    if ($chunk === false) break;
    echo $chunk;
    $left -= strlen($chunk);
    flush();
}
fclose($fh);
