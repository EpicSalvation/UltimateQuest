<?php
// api/delete_infraction.php — undo a single recorded infraction.
// POST JSON body: { id: int }
// Infractions are judgement calls made in a hurry during a live event, so an
// admin can always take one back; the rest of the team's log is untouched.
require_once __DIR__ . '/../lib/auth.php';
require_admin();
csrf_check();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required']); exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$id   = (int)($data['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing infraction id']); exit;
}

// Read the owning team first so the response can return the team's fresh total.
$st = db()->prepare('SELECT team_id FROM infractions WHERE id = ?');
$st->execute([$id]);
$team_id = $st->fetchColumn();
if ($team_id === false) {
    echo json_encode(['success' => false, 'message' => 'Infraction not found']); exit;
}

$del = db()->prepare('DELETE FROM infractions WHERE id = ?');
$del->execute([$id]);

echo json_encode([
    'success'     => true,
    'total'       => infraction_total((int)$team_id),
    'infractions' => infractions_for((int)$team_id),
]);
