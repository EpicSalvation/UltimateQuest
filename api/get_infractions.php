<?php
// api/get_infractions.php — list the infractions recorded against one team.
// GET ?team=<slug>. Used to populate the dashboard's penalty modal.
require_once __DIR__ . '/../lib/auth.php';
require_admin();
header('Content-Type: application/json');

$name = trim((string)($_GET['team'] ?? ''));
if ($name === '') {
    echo json_encode(['success' => false, 'message' => 'Missing team']); exit;
}

$st = db()->prepare('SELECT id FROM teams WHERE name = ?');
$st->execute([$name]);
$team_id = $st->fetchColumn();
if ($team_id === false) {
    echo json_encode(['success' => false, 'message' => 'Team not found']); exit;
}

echo json_encode([
    'success'     => true,
    'total'       => infraction_total((int)$team_id),
    'infractions' => infractions_for((int)$team_id),
]);
