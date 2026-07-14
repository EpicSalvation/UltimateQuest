<?php
require_once __DIR__ . '/../lib/auth.php';
require_admin();
header('Content-Type: application/json');

$team_name = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['team'] ?? '');
$task_id   = intval($_GET['task'] ?? 0);

if (!$team_name || !$task_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']); exit;
}

$st = db()->prepare(
    'SELECT s.id   AS submission_id,
            t.id   AS team_id,
            tk.id  AS task_id, tk.title, tk.points
       FROM submissions s
       JOIN teams t  ON t.id  = s.team_id
       JOIN tasks tk ON tk.id = s.task_id
      WHERE t.name = ? AND tk.id = ?'
);
$st->execute([$team_name, $task_id]);
$row = $st->fetch();
if (!$row) {
    echo json_encode(['success' => false, 'error' => "No submission for $team_name task $task_id"]);
    exit;
}

$fst = db()->prepare(
    'SELECT id, filename, mime_type, has_thumb, slideshow FROM submission_files WHERE submission_id = ? ORDER BY id'
);
$fst->execute([$row['submission_id']]);
$file_rows = $fst->fetchAll();

$base_full  = BASE_URL . "/api/file.php?team={$team_name}&task={$task_id}&f=";
$base_thumb = BASE_URL . "/api/thumb.php?team={$team_name}&task={$task_id}&f=";

$files = [];
foreach ($file_rows as $f) {
    $thumb_name = pathinfo($f['filename'], PATHINFO_FILENAME) . '.jpg';
    $files[] = [
        'id'        => (int)$f['id'],
        'name'      => $f['filename'],
        'url'       => $base_full . rawurlencode($f['filename']),
        'thumb_url' => $f['has_thumb'] ? $base_thumb . rawurlencode($thumb_name) : null,
        'has_thumb' => (bool)$f['has_thumb'],
        'type'      => $f['mime_type'],
        'slideshow' => (bool)$f['slideshow'],
    ];
}

echo json_encode([
    'success' => true,
    'task'    => ['title' => $row['title'], 'points' => (int)$row['points']],
    'files'   => $files,
]);
