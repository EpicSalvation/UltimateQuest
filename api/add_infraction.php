<?php
// api/add_infraction.php — record a point deduction against a team for a
// reported infraction. POST JSON body:
//   { team: "slug", points: int, reason: string }
// Each call appends a row; deductions accumulate and are undone individually
// via api/delete_infraction.php rather than being edited.
require_once __DIR__ . '/../lib/auth.php';
require_admin();
csrf_check();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required']); exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$name = trim((string)($data['team'] ?? ''));

if ($name === '') {
    echo json_encode(['success' => false, 'message' => 'Missing team']); exit;
}

$st = db()->prepare('SELECT id FROM teams WHERE name = ?');
$st->execute([$name]);
$team_id = $st->fetchColumn();
if ($team_id === false) {
    echo json_encode(['success' => false, 'message' => 'Team not found']); exit;
}

// Stored positive; admins may type "-25" meaning "dock 25", so normalize.
$points = min(INFRACTION_MAX_POINTS, abs((int)($data['points'] ?? 0)));
$reason = mb_substr(trim((string)($data['reason'] ?? '')), 0, 255);

if ($points <= 0) {
    echo json_encode(['success' => false, 'message' => 'Enter a point deduction greater than 0']); exit;
}

$in = db()->prepare('INSERT INTO infractions (team_id, points, reason) VALUES (?, ?, ?)');
$in->execute([$team_id, $points, $reason !== '' ? $reason : null]);

echo json_encode([
    'success'     => true,
    'id'          => (int)db()->lastInsertId(),
    'points'      => $points,
    'reason'      => $reason,
    'total'       => infraction_total((int)$team_id),
    'infractions' => infractions_for((int)$team_id),
]);
