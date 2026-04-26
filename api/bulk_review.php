<?php
// api/bulk_review.php — apply approve/reject to multiple submissions in one
// transaction. POST JSON body:
//   { action: 'approve'|'reject', items: [{team, task}, ...], note?: string }

require_once __DIR__ . '/../lib/auth.php';
require_admin();
csrf_check();
header('Content-Type: application/json');

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';
$items  = is_array($body['items'] ?? null) ? $body['items'] : [];
$note   = trim((string)($body['note'] ?? ''));

if (!in_array($action, ['approve', 'reject'], true) || !$items) {
    echo json_encode(['success' => false, 'message' => 'Invalid action or empty selection']); exit;
}

$pdo = db();
$status   = $action === 'approve' ? 'approved' : 'rejected';
$note_val = $action === 'approve' ? null : ($note !== '' ? $note : null);

$st = $pdo->prepare(
    "UPDATE submissions s
        JOIN teams t ON t.id = s.team_id
        SET s.status      = ?,
            s.note        = ?,
            s.reviewed_at = NOW()
      WHERE t.name = ? AND s.task_id = ? AND s.status = 'pending'"
);

$processed = 0;
$failed    = [];

try {
    $pdo->beginTransaction();
    foreach ($items as $item) {
        $team    = preg_replace('/[^a-zA-Z0-9_]/', '', $item['team'] ?? '');
        $task_id = intval($item['task'] ?? 0);
        if (!$team || !$task_id) {
            $failed[] = ['team' => $item['team'] ?? null, 'task' => $item['task'] ?? null, 'reason' => 'invalid'];
            continue;
        }
        $st->execute([$status, $note_val, $team, $task_id]);
        if ($st->rowCount() > 0) $processed++;
        else $failed[] = ['team' => $team, 'task' => $task_id, 'reason' => 'not pending'];
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]); exit;
}

echo json_encode([
    'success'   => true,
    'processed' => $processed,
    'failed'    => $failed,
    'message'   => "$action: $processed of " . count($items) . " applied",
]);
