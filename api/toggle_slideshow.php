<?php
// api/toggle_slideshow.php — flag/unflag a submitted file for the
// end-of-event slideshow. POST JSON body: { file_id: int, on: bool }.
require_once __DIR__ . '/../lib/auth.php';
require_admin();
csrf_check();
header('Content-Type: application/json');

$data    = json_decode(file_get_contents('php://input'), true) ?? [];
$file_id = intval($data['file_id'] ?? 0);
$on      = !empty($data['on']) ? 1 : 0;

if ($file_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid file id']); exit;
}

$st = db()->prepare('UPDATE submission_files SET slideshow = ? WHERE id = ?');
$st->execute([$on, $file_id]);

$check = db()->prepare('SELECT slideshow FROM submission_files WHERE id = ?');
$check->execute([$file_id]);
$row = $check->fetch();
if (!$row) {
    echo json_encode(['success' => false, 'message' => 'File not found']); exit;
}

echo json_encode(['success' => true, 'slideshow' => (bool)$row['slideshow']]);
