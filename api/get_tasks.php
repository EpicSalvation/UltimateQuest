<?php
require_once __DIR__ . '/../lib/auth.php';
require_admin();
header('Content-Type: application/json');

$rows = db()->query(
    'SELECT id, title, description, points, penalty,
            photos_required AS photos, videos_required AS videos,
            mandatory
       FROM tasks
       ORDER BY sort_order, id'
)->fetchAll();

foreach ($rows as &$r) {
    $r['id']        = (int)$r['id'];
    $r['points']    = (int)$r['points'];
    $r['penalty']   = (int)$r['penalty'];
    $r['photos']    = (int)$r['photos'];
    $r['videos']    = (int)$r['videos'];
    $r['mandatory'] = (int)$r['mandatory'];
}
echo json_encode(['success' => true, 'tasks' => $rows]);
