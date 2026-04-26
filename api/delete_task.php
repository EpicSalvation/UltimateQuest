<?php
require_once __DIR__ . '/../lib/auth.php';
require_admin();
csrf_check();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']); exit;
}

$task_id = intval($_POST['task_id'] ?? 0);
if ($task_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid task ID']); exit;
}

// FK ON DELETE CASCADE drops dependent submissions and submission_files.
$st = db()->prepare('DELETE FROM tasks WHERE id = ?');
$st->execute([$task_id]);

echo json_encode(
    $st->rowCount() > 0
        ? ['success' => true,  'message' => 'Task deleted successfully']
        : ['success' => false, 'error'   => 'Task not found']
);
