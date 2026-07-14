<?php
// api/download_slideshow.php — admin-only one-click ZIP of every file
// flagged for the slideshow. Files are placed under <team>/ inside the ZIP
// with the task id and original name preserved, so the download unpacks
// into a ready-to-present folder.
require_once __DIR__ . '/../lib/auth.php';
require_admin();

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('ZipArchive not available on this server.');
}

$rows = db()->query(
    "SELECT sf.filename, t.name AS team, s.task_id
       FROM submission_files sf
       JOIN submissions s ON s.id = sf.submission_id
       JOIN teams t       ON t.id = s.team_id
      WHERE sf.slideshow = 1
      ORDER BY t.name, s.task_id, sf.id"
)->fetchAll();

if (!$rows) {
    http_response_code(404);
    exit('No files are marked for the slideshow yet.');
}

$tmp = tempnam(sys_get_temp_dir(), 'uq_show_');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    @unlink($tmp);
    http_response_code(500); exit('Could not create zip');
}

$added = 0;
foreach ($rows as $r) {
    // Same sanitization as file.php — team names are [a-z0-9_] slugs and
    // filenames are server-generated, but never trust rows blindly.
    $team = preg_replace('/[^a-zA-Z0-9_]/', '', $r['team']);
    $file = basename($r['filename']);
    $path = DATA_DIR . "/uploads/{$team}/{$r['task_id']}/{$file}";
    if (!is_file($path)) continue; // e.g. resubmitted since it was flagged
    $zip->addFile($path, "slideshow/{$team}/task{$r['task_id']}_{$file}");
    $added++;
}
$zip->close();

if ($added === 0) {
    @unlink($tmp);
    http_response_code(404);
    exit('Marked files are no longer on disk (they may have been resubmitted).');
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="slideshow_' . date('Ymd_His') . '.zip"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: private, no-store');
readfile($tmp);
@unlink($tmp);
