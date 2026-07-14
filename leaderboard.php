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
$rows = db()->query(
    "SELECT t.name,
            COALESCE(SUM(CASE WHEN s.status='approved' THEN tk.points + tk.penalty ELSE 0 END), 0)
              - (SELECT COALESCE(SUM(penalty), 0) FROM tasks) AS score,
            COALESCE(SUM(CASE WHEN s.status='pending'  THEN tk.points ELSE 0 END), 0) AS pending,
            MAX(s.reviewed_at) AS last_reviewed
       FROM teams t
       LEFT JOIN submissions s ON s.team_id = t.id
       LEFT JOIN tasks tk      ON tk.id    = s.task_id
       GROUP BY t.id, t.name
       ORDER BY score DESC, t.name"
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
      <div class="row">
        <div class="name"><strong><?=htmlspecialchars(str_replace('_',' ',$r['name']))?></strong></div>
        <div class="score"><?=(int)$r['score']?> pts</div>
        <div class="time small">
          <?php if ((int)$r['pending'] > 0): ?>
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
