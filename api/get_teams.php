<?php
require_once __DIR__ . '/../lib/auth.php';
require_admin();
header('Content-Type: application/json');

// Scores derived from submissions JOIN tasks. LEFT JOIN so teams with no submissions still appear.
$rows = db()->query(
    "SELECT t.name,
            COALESCE(SUM(CASE WHEN s.status='approved' THEN tk.points ELSE 0 END), 0) AS score_awarded,
            COALESCE(SUM(CASE WHEN s.status='pending'  THEN tk.points ELSE 0 END), 0) AS score_pending
       FROM teams t
       LEFT JOIN submissions s ON s.team_id = t.id
       LEFT JOIN tasks tk      ON tk.id    = s.task_id
       GROUP BY t.id, t.name
       ORDER BY score_awarded DESC, t.name"
)->fetchAll();

foreach ($rows as &$r) {
    $r['score_awarded'] = (int)$r['score_awarded'];
    $r['score_pending'] = (int)$r['score_pending'];
}
echo json_encode(['success' => true, 'teams' => $rows]);
