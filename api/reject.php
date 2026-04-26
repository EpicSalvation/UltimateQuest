<?php
require_once __DIR__ . '/../lib/auth.php';
require_admin();
csrf_check();
header('Content-Type: application/json');

$data    = json_decode(file_get_contents('php://input'), true) ?? [];
$team    = preg_replace('/[^a-zA-Z0-9_]/', '', $data['team'] ?? '');
$task_id = intval($data['task'] ?? 0);
$note    = trim($data['note'] ?? '');

if (!$team || !$task_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']); exit;
}

$pdo = db();
$st = $pdo->prepare(
    "UPDATE submissions s
        JOIN teams t ON t.id = s.team_id
        SET s.status      = 'rejected',
            s.note        = ?,
            s.reviewed_at = NOW()
      WHERE t.name = ? AND s.task_id = ? AND s.status = 'pending'"
);
$st->execute([$note, $team, $task_id]);

if ($st->rowCount() === 0) {
    echo json_encode(['success' => false, 'message' => 'No matching pending submission']); exit;
}

$tt = $pdo->prepare('SELECT title FROM tasks WHERE id = ?');
$tt->execute([$task_id]);
$title = ($tt->fetch()['title'] ?? "task $task_id");

echo json_encode(['success' => true, 'message' => "Rejected: $team – $title"]);
