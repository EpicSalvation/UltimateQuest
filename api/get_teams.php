<?php
require_once __DIR__ . '/../lib/auth.php';
require_admin();
header('Content-Type: application/json');

// Scores derived from submissions JOIN tasks. LEFT JOIN so teams with no submissions still appear.
// Every task's penalty counts against a team until that task is approved, so:
//   net = SUM(approved: points + penalty) - SUM(all task penalties)
// The late-arrival and infraction deductions come off the net; a disqualified
// team scores 0 and sorts last (see lib/dbx.php for the tariff, the challenge
// voids, and the infraction log).
$late_ded = sql_late_deduction('t');
$late_dq  = sql_late_dq('t');
$infr_ded = sql_infraction_deduction('t');

$rows = db()->query(
    "SELECT t.name, t.late_minutes, t.late_void, t.dq_void, t.late_note,
            $infr_ded AS infraction_deduction,
            COALESCE(SUM(CASE WHEN s.status='approved' THEN tk.points ELSE 0 END), 0) AS score_awarded,
            COALESCE(SUM(CASE WHEN s.status='pending'  THEN tk.points ELSE 0 END), 0) AS score_pending,
            (SELECT COALESCE(SUM(penalty), 0) FROM tasks)
              - COALESCE(SUM(CASE WHEN s.status='approved' THEN tk.penalty ELSE 0 END), 0) AS penalty_outstanding,
            $late_ded AS late_deduction,
            $late_dq  AS disqualified,
            COALESCE(SUM(CASE WHEN s.status='approved' THEN tk.points + tk.penalty ELSE 0 END), 0)
              - (SELECT COALESCE(SUM(penalty), 0) FROM tasks)
              - $late_ded - $infr_ded AS score_net
       FROM teams t
       LEFT JOIN submissions s ON s.team_id = t.id
       LEFT JOIN tasks tk      ON tk.id    = s.task_id
       GROUP BY t.id, t.name, t.late_note, " . sql_late_group_by('t') . "
       ORDER BY disqualified ASC, score_net DESC, t.name"
)->fetchAll();

foreach ($rows as &$r) {
    $r['score_awarded']       = (int)$r['score_awarded'];
    $r['score_pending']       = (int)$r['score_pending'];
    $r['penalty_outstanding'] = (int)$r['penalty_outstanding'];
    $r['late_deduction']      = (int)$r['late_deduction'];
    $r['infraction_deduction'] = (int)$r['infraction_deduction'];
    $r['disqualified']        = (bool)$r['disqualified'];
    $r['late_minutes']        = $r['late_minutes'] === null ? null : (int)$r['late_minutes'];
    $r['late_void']           = (bool)$r['late_void'];
    $r['dq_void']             = (bool)$r['dq_void'];
    $r['late_note']           = (string)($r['late_note'] ?? '');
    // Admins see the pre-DQ number too, so a challenge can be judged on merit.
    $r['score_before_dq']     = (int)$r['score_net'];
    $r['score_net']           = $r['disqualified'] ? 0 : (int)$r['score_net'];
}
unset($r);
echo json_encode(['success' => true, 'teams' => $rows]);
