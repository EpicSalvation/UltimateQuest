<?php
require_once __DIR__ . '/../lib/auth.php';
require_admin();
csrf_check();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']); exit;
}

$task_no     = mb_substr(trim($_POST['task_no'] ?? ''), 0, 16); // display label ("1a"/"3b")
$title       = trim($_POST['title'] ?? '');
$points      = intval($_POST['points'] ?? 0);
$penalty     = abs(intval($_POST['penalty'] ?? 0)); // stored positive; scoring subtracts it
$photos      = intval($_POST['photos'] ?? 0);
$videos      = intval($_POST['videos'] ?? 0);
$description = trim($_POST['description'] ?? '');
$mandatory   = !empty($_POST['mandatory']) ? 1 : 0; // "Priority" flag in the UI
$bonus       = !empty($_POST['bonus']) ? 1 : 0;     // "Bonus challenge" flag

if (!$title || $points <= 0) {
    echo json_encode(['success' => false, 'error' => 'Title and positive points required']); exit;
}

$st = db()->prepare(
    'INSERT INTO tasks (task_no, title, description, points, penalty, photos_required, videos_required, mandatory, bonus, sort_order)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE((SELECT max_o FROM (SELECT MAX(sort_order) AS max_o FROM tasks) x), 0) + 1)'
);
$st->execute([$task_no !== '' ? $task_no : null, $title, $description, $points, $penalty, $photos, $videos, $mandatory, $bonus]);

echo json_encode(['success' => true, 'message' => 'Task added successfully']);
