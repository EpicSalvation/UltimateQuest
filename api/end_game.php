<?php
// api/end_game.php — snapshot the current game into DATA_DIR/games/<label>/,
// then reset the live tables/uploads to a pristine state for the next event.
//
// POST JSON body:
//   { delete_teams: bool, delete_tasks: bool, reset_event_times: bool }
//
// Always preserves settings.admin_password_hash. Always wipes submissions +
// submission_files. Records the run in the past_games table.

require_once __DIR__ . '/../lib/auth.php';
require_admin();
csrf_check();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required']); exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$delete_teams      = !empty($body['delete_teams']);
$delete_tasks      = !empty($body['delete_tasks']);
$reset_event_times = !empty($body['reset_event_times']);

$label       = date('Ymd_His');
$archive_dir = DATA_DIR . '/games/' . $label;

if (is_dir($archive_dir)) {
    echo json_encode(['success' => false, 'message' => 'Archive already exists for this second; retry shortly']); exit;
}
if (!@mkdir($archive_dir, 0775, true)) {
    echo json_encode(['success' => false, 'message' => 'Could not create archive directory']); exit;
}

$pdo = db();

// ---- Pull data for results + game_data dumps. ----------------------------

// score is the net result: approved points minus the penalty of every task the
// team did not complete (SUM(approved: points + penalty) - SUM(all penalties)).
// The late-arrival deduction comes off that net; disqualified teams score 0
// and sort last. The minutes and challenge voids are archived alongside so the
// final standings can be re-derived from the dump.
$late_ded = sql_late_deduction('t');
$late_dq  = sql_late_dq('t');

$leaderboard = $pdo->query(
    "SELECT t.id, t.name, t.display_name, t.late_minutes, t.late_void, t.dq_void, t.late_note,
            $late_ded AS late_deduction,
            $late_dq  AS disqualified,
            COALESCE(SUM(CASE WHEN s.status='approved' THEN tk.points + tk.penalty ELSE 0 END), 0)
              - (SELECT COALESCE(SUM(penalty), 0) FROM tasks)
              - $late_ded AS score,
            COALESCE(SUM(CASE WHEN s.status='approved' THEN tk.points ELSE 0 END), 0) AS points_awarded,
            COALESCE(SUM(CASE WHEN s.status='approved' THEN 1 ELSE 0 END), 0) AS approved,
            COALESCE(SUM(CASE WHEN s.status='pending'  THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN s.status='rejected' THEN 1 ELSE 0 END), 0) AS rejected
       FROM teams t
       LEFT JOIN submissions s ON s.team_id = t.id
       LEFT JOIN tasks tk      ON tk.id    = s.task_id
       GROUP BY t.id, t.name, t.display_name, t.late_note, " . sql_late_group_by('t') . "
       ORDER BY disqualified ASC, score DESC, t.name"
)->fetchAll();

// A disqualified team's final score is 0 — apply it once, here, so the results
// HTML, the summary and the JSON dump all agree.
foreach ($leaderboard as &$lb) {
    $lb['score_before_dq'] = (int)$lb['score'];
    if ((int)$lb['disqualified'] === 1) $lb['score'] = 0;
}
unset($lb);

$per_task = $pdo->query(
    "SELECT tk.id, tk.title, tk.points, tk.penalty,
            COALESCE(SUM(CASE WHEN s.status='approved' THEN 1 ELSE 0 END), 0) AS approved,
            COALESCE(SUM(CASE WHEN s.status='rejected' THEN 1 ELSE 0 END), 0) AS rejected,
            COALESCE(SUM(CASE WHEN s.status='pending'  THEN 1 ELSE 0 END), 0) AS pending
       FROM tasks tk
       LEFT JOIN submissions s ON s.task_id = tk.id
       GROUP BY tk.id, tk.title, tk.points, tk.penalty
       ORDER BY tk.sort_order, tk.id"
)->fetchAll();

$status_counts = $pdo->query(
    "SELECT status, COUNT(*) AS n FROM submissions GROUP BY status"
)->fetchAll();
$counts_by_status = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($status_counts as $r) $counts_by_status[$r['status']] = (int)$r['n'];

$file_stats = $pdo->query(
    "SELECT COUNT(*) AS n, COALESCE(SUM(byte_size), 0) AS bytes FROM submission_files"
)->fetch();

$teams_dump          = $pdo->query("SELECT id, name, display_name, late_minutes, late_void, dq_void, late_note, late_updated_at, created_at FROM teams ORDER BY id")->fetchAll();
$tasks_dump          = $pdo->query("SELECT id, title, description, points, penalty, photos_required, videos_required, mandatory, sort_order, created_at FROM tasks ORDER BY sort_order, id")->fetchAll();
$submissions_dump    = $pdo->query("SELECT id, team_id, task_id, status, note, submitted_at, reviewed_at FROM submissions ORDER BY id")->fetchAll();
$submission_files_dp = $pdo->query("SELECT id, submission_id, filename, mime_type, byte_size, has_thumb, created_at FROM submission_files ORDER BY id")->fetchAll();
$settings_dump       = $pdo->query("SELECT k, v FROM settings WHERE k <> 'admin_password_hash' ORDER BY k")->fetchAll();

// ---- Write results.json + manifest + game_data + results.html ------------

$results = [
    'label'        => $label,
    'ended_at'     => date('Y-m-d H:i:s'),
    'event_start'  => setting('event_start_time'),
    'event_end'    => setting('event_end_time'),
    'leaderboard'  => $leaderboard,
    'per_task'     => $per_task,
    'submissions'  => $counts_by_status,
    'files_count'  => (int)$file_stats['n'],
    'files_bytes'  => (int)$file_stats['bytes'],
];

$manifest = [
    'label'              => $label,
    'ended_at'           => $results['ended_at'],
    'team_count'         => count($leaderboard),
    'task_count'         => count($per_task),
    'submission_counts'  => $counts_by_status,
    'files_count'        => $results['files_count'],
    'files_bytes'        => $results['files_bytes'],
    'top_score'          => $leaderboard[0]['score'] ?? 0,
    'top_team'           => $leaderboard[0]['display_name'] ?? null,
    'cleared'            => [
        'submissions'        => true,
        'teams'              => $delete_teams,
        'tasks'              => $delete_tasks,
        'event_time_reset'   => $reset_event_times,
    ],
    'schema_version'     => 1,
];

$game_data = [
    'teams'            => $teams_dump,
    'tasks'            => $tasks_dump,
    'submissions'      => $submissions_dump,
    'submission_files' => $submission_files_dp,
    'settings'         => $settings_dump,
];

file_put_contents($archive_dir . '/results.json',  json_encode($results,  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($archive_dir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($archive_dir . '/game_data.json', json_encode($game_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Human-readable HTML summary (self-contained — no relative asset deps).
$html  = "<!doctype html><html><head><meta charset='utf-8'><title>Game $label</title>";
$html .= "<style>body{font-family:system-ui,sans-serif;max-width:800px;margin:2em auto;padding:0 1em}";
$html .= "table{border-collapse:collapse;width:100%;margin:1em 0}th,td{border:1px solid #ccc;padding:.4em .6em;text-align:left}";
$html .= "th{background:#f0f0f0}.muted{color:#888}</style></head><body>";
$html .= "<h1>The Ultimate Quest — Game " . htmlspecialchars($label) . "</h1>";
$html .= "<p class='muted'>Ended " . htmlspecialchars($results['ended_at']) . " ET. ";
$html .= "Submissions: {$counts_by_status['approved']} approved · {$counts_by_status['rejected']} rejected · {$counts_by_status['pending']} pending. ";
$html .= "{$results['files_count']} files / " . number_format($results['files_bytes']) . " bytes.</p>";
$html .= "<h2>Leaderboard</h2><table><tr><th>Rank</th><th>Team</th><th>Score</th><th>Late</th><th>Approved</th><th>Rejected</th></tr>";
foreach ($leaderboard as $i => $r) {
    $dq   = (int)$r['disqualified'] === 1;
    $late = late_ruling_text(
        $r['late_minutes'] === null ? null : (int)$r['late_minutes'],
        (bool)$r['late_void'], (bool)$r['dq_void']
    );
    $html .= '<tr><td>' . ($dq ? '—' : $i + 1) . '</td><td>' . htmlspecialchars($r['display_name'] ?: $r['name'])
          . ($dq ? ' <strong>(DISQUALIFIED)</strong>' : '')
          . '</td><td>' . (int)$r['score'] . '</td><td>' . htmlspecialchars($late)
          . '</td><td>' . (int)$r['approved'] . '</td><td>' . (int)$r['rejected'] . '</td></tr>';
}
$html .= "</table><h2>Per-task</h2><table><tr><th>Task</th><th>Points</th><th>Penalty</th><th>Approved</th><th>Rejected</th></tr>";
foreach ($per_task as $r) {
    $html .= '<tr><td>' . htmlspecialchars($r['title']) . '</td><td>' . (int)$r['points']
          . '</td><td>' . ((int)$r['penalty'] > 0 ? '-' . (int)$r['penalty'] : '')
          . '</td><td>' . (int)$r['approved'] . '</td><td>' . (int)$r['rejected'] . '</td></tr>';
}
$html .= "</table></body></html>";
file_put_contents($archive_dir . '/results.html', $html);

// ---- Move uploads into the archive (atomic dir rename). -----------------

$uploads_src = DATA_DIR . '/uploads';
if (is_dir($uploads_src)) {
    if (!@rename($uploads_src, $archive_dir . '/uploads')) {
        echo json_encode(['success' => false, 'message' => 'Failed to move uploads dir into archive']); exit;
    }
}
@mkdir($uploads_src, 0775, true); // recreate empty uploads root for the next game

// ---- DB cleanup. --------------------------------------------------------

try {
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM submission_files');
    $pdo->exec('DELETE FROM submissions');
    if ($delete_teams) $pdo->exec('DELETE FROM teams');
    // Late-arrival rulings belong to the game that just ended — clear them off
    // any teams that survive into the next one.
    else $pdo->exec('UPDATE teams SET late_minutes = NULL, late_void = 0, dq_void = 0,
                                      late_note = NULL, late_updated_at = NULL');
    if ($delete_tasks) $pdo->exec('DELETE FROM tasks');
    if ($reset_event_times) {
        set_setting('event_start_time',   null);
        set_setting('event_end_time',     null);
        set_setting('reveal_leaderboard', '0');
    }

    $pi = $pdo->prepare(
        'INSERT INTO past_games (label, archive_path, summary_json) VALUES (?, ?, ?)'
    );
    $pi->execute([$label, $archive_dir, json_encode($manifest, JSON_UNESCAPED_SLASHES)]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'DB cleanup failed: ' . $e->getMessage()]); exit;
}

echo json_encode([
    'success'      => true,
    'label'        => $label,
    'download_url' => BASE_URL . '/api/download_game_archive.php?label=' . urlencode($label),
]);
