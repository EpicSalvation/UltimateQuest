<?php
require_once __DIR__ . '/../lib/auth.php';
require_admin();
header('Content-Type: application/json');

$rows = db()->query(
    'SELECT id, task_no, title, description, points, penalty,
            photos_required AS photos, videos_required AS videos,
            mandatory, bonus
       FROM tasks
       ORDER BY sort_order, id'
)->fetchAll();

// First N tasks (by list order) are the starter/gate tasks — flag them so the
// admin table can label them alongside the "first N tasks" control.
$starter_ids = array_flip(starter_task_ids(starter_gate_count()));

foreach ($rows as &$r) {
    $r['id']        = (int)$r['id'];
    $r['task_no']   = $r['task_no'] ?? '';
    $r['points']    = (int)$r['points'];
    $r['penalty']   = (int)$r['penalty'];
    $r['photos']    = (int)$r['photos'];
    $r['videos']    = (int)$r['videos'];
    $r['mandatory'] = (int)$r['mandatory'];
    $r['bonus']     = (int)$r['bonus'];
    $r['starter']   = isset($starter_ids[$r['id']]) ? 1 : 0;
}
echo json_encode(['success' => true, 'tasks' => $rows]);
