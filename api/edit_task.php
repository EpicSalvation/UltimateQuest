<?php
require_once __DIR__ . '/../lib/auth.php';
require_admin();
csrf_check();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']); exit;
}

$task_id     = intval($_POST['task_id'] ?? 0);
$title       = trim($_POST['title'] ?? '');
$points      = intval($_POST['points'] ?? 0);
$penalty     = abs(intval($_POST['penalty'] ?? 0)); // stored positive; scoring subtracts it
$photos      = intval($_POST['photos'] ?? 0);
$videos      = intval($_POST['videos'] ?? 0);
$description = trim($_POST['description'] ?? '');
$mandatory   = !empty($_POST['mandatory']) ? 1 : 0; // "Priority" flag in the UI

if ($task_id <= 0 || !$title || $points <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing or invalid data']); exit;
}

$st = db()->prepare(
    'UPDATE tasks
        SET title = ?, description = ?, points = ?, penalty = ?, photos_required = ?, videos_required = ?, mandatory = ?
      WHERE id = ?'
);
$st->execute([$title, $description, $points, $penalty, $photos, $videos, $mandatory, $task_id]);

if ($st->rowCount() === 0) {
    // rowCount is 0 for unchanged rows too — verify existence explicitly.
    $check = db()->prepare('SELECT 1 FROM tasks WHERE id = ?');
    $check->execute([$task_id]);
    if (!$check->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Task not found']); exit;
    }
}

echo json_encode(['success' => true, 'message' => 'Task updated successfully']);
