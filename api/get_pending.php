<?php
require_once __DIR__ . '/../lib/auth.php';
require_admin();
header('Content-Type: application/json');

$rows = db()->query(
    "SELECT t.name AS team,
            tk.id AS task_id, tk.task_no, tk.title, tk.points,
            s.submitted_at
       FROM submissions s
       JOIN teams t  ON t.id  = s.team_id
       JOIN tasks tk ON tk.id = s.task_id
      WHERE s.status = 'pending'
      ORDER BY s.submitted_at ASC"
)->fetchAll();

foreach ($rows as &$r) {
    $r['task_id'] = (int)$r['task_id'];
    $r['points']  = (int)$r['points'];
    // Show the human task number ("1a") alongside the title where present.
    $no = trim((string)($r['task_no'] ?? ''));
    $r['task'] = ($no !== '' ? $no . ' — ' : '') . $r['title'];
    unset($r['task_no'], $r['title']);
}
echo json_encode(['success' => true, 'items' => $rows]);
