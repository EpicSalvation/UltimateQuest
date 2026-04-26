<?php
require_once __DIR__ . '/../lib/auth.php';
require_admin();
header('Content-Type: application/json');

$rows = db()->query(
    "SELECT t.name AS team,
            tk.id AS task_id, tk.title AS task, tk.points,
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
}
echo json_encode(['success' => true, 'items' => $rows]);
