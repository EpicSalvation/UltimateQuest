<?php
require_once __DIR__ . '/../lib/auth.php';
require_admin();
csrf_check();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']); exit;
}

$title       = trim($_POST['title'] ?? '');
$points      = intval($_POST['points'] ?? 0);
$photos      = intval($_POST['photos'] ?? 0);
$videos      = intval($_POST['videos'] ?? 0);
$description = trim($_POST['description'] ?? '');

if (!$title || $points <= 0) {
    echo json_encode(['success' => false, 'error' => 'Title and positive points required']); exit;
}

$st = db()->prepare(
    'INSERT INTO tasks (title, description, points, photos_required, videos_required, sort_order)
     VALUES (?, ?, ?, ?, ?, COALESCE((SELECT max_o FROM (SELECT MAX(sort_order) AS max_o FROM tasks) x), 0) + 1)'
);
$st->execute([$title, $description, $points, $photos, $videos]);

echo json_encode(['success' => true, 'message' => 'Task added successfully']);
