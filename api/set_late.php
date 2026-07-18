<?php
// api/set_late.php — record (or clear) a team's late arrival back to homebase,
// and rule on a challenge. POST JSON body:
//   { team: "slug", minutes: int|null, late_void: bool, dq_void: bool, note: string }
// `minutes` null/empty clears the whole ruling; the two voids reverse the
// point deduction and the disqualification independently.
require_once __DIR__ . '/../lib/auth.php';
require_admin();
csrf_check();
header('Content-Type: application/json');

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

$raw     = $data['minutes'] ?? null;
$clear   = $raw === null || $raw === '' || (int)$raw <= 0;
$minutes = $clear ? null : min(999, abs((int)$raw));
$note    = mb_substr(trim((string)($data['note'] ?? '')), 0, 255);

// Voids only mean something while a ruling exists; clearing drops them too.
$late_void = (!$clear && !empty($data['late_void'])) ? 1 : 0;
$dq_void   = (!$clear && !empty($data['dq_void']))   ? 1 : 0;

$up = db()->prepare(
    'UPDATE teams
        SET late_minutes = ?, late_void = ?, dq_void = ?, late_note = ?,
            late_updated_at = NOW()
      WHERE id = ?'
);
$up->execute([$minutes, $late_void, $dq_void, $note !== '' ? $note : null, $team_id]);

echo json_encode([
    'success'        => true,
    'late_minutes'   => $minutes,
    'late_void'      => (bool)$late_void,
    'dq_void'        => (bool)$dq_void,
    'late_note'      => $note,
    'late_deduction' => late_deduction($minutes, (bool)$late_void),
    'disqualified'   => late_disqualified($minutes, (bool)$dq_void),
    'ruling'         => late_ruling_text($minutes, (bool)$late_void, (bool)$dq_void),
]);
