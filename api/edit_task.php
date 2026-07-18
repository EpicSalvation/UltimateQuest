<?php
require_once __DIR__ . '/../lib/auth.php';
require_admin();
csrf_check();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']); exit;
}

$task_id     = intval($_POST['task_id'] ?? 0);
$task_no     = mb_substr(trim($_POST['task_no'] ?? ''), 0, 16); // display label ("1a"/"3b")
$title       = trim($_POST['title'] ?? '');
$points      = intval($_POST['points'] ?? 0);
$penalty     = abs(intval($_POST['penalty'] ?? 0)); // stored positive; scoring subtracts it
$photos      = intval($_POST['photos'] ?? 0);
$videos      = intval($_POST['videos'] ?? 0);
$description = trim($_POST['description'] ?? '');
$mandatory   = !empty($_POST['mandatory']) ? 1 : 0; // "Priority" flag in the UI
$bonus       = !empty($_POST['bonus']) ? 1 : 0;     // "Bonus challenge" flag

if ($task_id <= 0 || !$title || $points <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing or invalid data']); exit;
}

$st = db()->prepare(
    'UPDATE tasks
        SET task_no = ?, title = ?, description = ?, points = ?, penalty = ?, photos_required = ?, videos_required = ?, mandatory = ?, bonus = ?
      WHERE id = ?'
);
$st->execute([$task_no !== '' ? $task_no : null, $title, $description, $points, $penalty, $photos, $videos, $mandatory, $bonus, $task_id]);

if ($st->rowCount() === 0) {
    // rowCount is 0 for unchanged rows too — verify existence explicitly.
    $check = db()->prepare('SELECT 1 FROM tasks WHERE id = ?');
    $check->execute([$task_id]);
    if (!$check->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Task not found']); exit;
    }
}

echo json_encode(['success' => true, 'message' => 'Task updated successfully']);
