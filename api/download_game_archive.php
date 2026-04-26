<?php
// api/download_game_archive.php — admin-only ZIP download of an archived game.
// Builds the ZIP into a temp file, streams it, then deletes the temp.

require_once __DIR__ . '/../lib/auth.php';
require_admin();

$label = preg_replace('/[^0-9_]/', '', $_GET['label'] ?? '');
if ($label === '') { http_response_code(400); exit('Bad label'); }

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('ZipArchive not available on this server. Download files manually from DATA_DIR/games/' . $label . '/');
}

$st = db()->prepare('SELECT archive_path FROM past_games WHERE label = ?');
$st->execute([$label]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit('Unknown game'); }

$dir = $row['archive_path'];
// Defense in depth: enforce the path is under DATA_DIR/games/.
$expected_prefix = DATA_DIR . '/games/';
if (strncmp($dir, $expected_prefix, strlen($expected_prefix)) !== 0 || !is_dir($dir)) {
    http_response_code(404); exit('Archive directory missing');
}

$tmp = tempnam(sys_get_temp_dir(), 'uq_zip_');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    @unlink($tmp);
    http_response_code(500); exit('Could not create zip');
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
$prefix_len = strlen($dir) + 1;
foreach ($it as $file) {
    if (!$file->isFile()) continue;
    $local = 'game_' . $label . '/' . substr($file->getPathname(), $prefix_len);
    $zip->addFile($file->getPathname(), str_replace('\\', '/', $local));
}
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="game_' . $label . '.zip"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: private, no-store');
readfile($tmp);
@unlink($tmp);
