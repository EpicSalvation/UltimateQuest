<?php
require_once __DIR__ . '/lib/auth.php';

$team    = current_team();
$isAdmin = is_admin();

if (!$team && !$isAdmin) {
    header('Location: ' . BASE_URL . '/index.php'); exit;
}

if (!$isAdmin && setting('reveal_leaderboard') !== '1') {
    header('Location: ' . BASE_URL . '/team.php'); exit;
}

// Net score: approved points minus the penalty of every task the team has
// not (yet) completed — i.e. SUM(approved: points + penalty) - SUM(all penalties).
// …minus the late-arrival and infraction deductions. Disqualified teams score
// 0 and sort last.
$late_ded = sql_late_deduction('t');
$late_dq  = sql_late_dq('t');
$infr_ded = sql_infraction_deduction('t');

$rows = db()->query(
    "SELECT t.name, t.late_minutes,
            $late_ded AS late_deduction,
            $infr_ded AS infraction_deduction,
            $late_dq  AS disqualified,
            COALESCE(SUM(CASE WHEN s.status='approved' THEN tk.points + tk.penalty ELSE 0 END), 0)
              - (SELECT COALESCE(SUM(penalty), 0) FROM tasks)
              - $late_ded - $infr_ded AS score,
            COALESCE(SUM(CASE WHEN s.status='pending'  THEN tk.points ELSE 0 END), 0) AS pending,
            MAX(s.reviewed_at) AS last_reviewed
       FROM teams t
       LEFT JOIN submissions s ON s.team_id = t.id
       LEFT JOIN tasks tk      ON tk.id    = s.task_id
       GROUP BY t.id, t.name, " . sql_late_group_by('t') . "
       ORDER BY disqualified ASC, score DESC, t.name"
)->fetchAll();
?>
<!doctype html>
<html<?=theme_html_attr()?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="<?=BASE_URL?>/style.css?v=<?=@filemtime(__DIR__.'/style.css')?>">
<title>Leaderboard</title>
<meta http-equiv="refresh" content="30">
</head>
<body class="container">
  <h1>Leaderboard</h1>
  <?php if (!$rows): ?>
    <p class="small">No teams registered yet.</p>
  <?php else: ?>
    <?php foreach ($rows as $r): ?>
      <?php $dq = (int)$r['disqualified'] === 1; ?>
      <div class="row"<?= $dq ? ' style="opacity:.65;"' : '' ?>>
        <div class="name"><strong><?=htmlspecialchars(str_replace('_',' ',$r['name']))?></strong>
          <?php if ($dq): ?><span class="small" style="color:#c00; font-weight:700;"> DISQUALIFIED</span><?php endif; ?>
        </div>
        <div class="score"><?= $dq ? '0' : (int)$r['score'] ?> pts</div>
        <div class="time small">
          <?php if ((int)$r['late_deduction'] > 0 || (int)$r['infraction_deduction'] > 0): ?>
            <?php if ((int)$r['late_deduction'] > 0): ?>⏰ −<?=(int)$r['late_deduction']?> late<?php endif; ?>
            <?php if ((int)$r['infraction_deduction'] > 0): ?>⚠️ −<?=(int)$r['infraction_deduction']?> penalty<?php endif; ?>
          <?php elseif ((int)$r['pending'] > 0): ?>
            🕓 <?=(int)$r['pending']?> pts pending
          <?php else: ?>
            &nbsp;
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  <p class="center"><a href="<?=BASE_URL?>/<?=$isAdmin ? 'admin/dashboard.php' : 'team.php'?>">Back</a></p>
</body>
</html>
