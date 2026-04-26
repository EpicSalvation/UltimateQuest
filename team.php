<?php
require_once __DIR__ . '/lib/auth.php';
require_team();

date_default_timezone_set('America/New_York');

$team    = current_team();
$team_id = current_team_id();

$start_str = setting('event_start_time');
$end_str   = setting('event_end_time');

$start = $start_str ? new DateTime($start_str, new DateTimeZone('America/New_York')) : null;
$end   = $end_str   ? new DateTime($end_str,   new DateTimeZone('America/New_York')) : null;
$now   = new DateTime('now', new DateTimeZone('America/New_York'));

if ($start && $now < $start) {
    $diff = $start->getTimestamp() - $now->getTimestamp();
    ?>
    <!doctype html>
    <html<?=theme_html_attr()?>>
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <link rel="stylesheet" href="<?=BASE_URL?>/style.css?v=<?=@filemtime(__DIR__.'/style.css')?>">
      <title>The Ultimate Quest – Coming Soon</title>
    </head>
    <body class="container center">
      <div class="card center">
        <h2>The Ultimate Quest begins soon!</h2>
        <p id="countdown" class="big" data-seconds="<?=$diff?>">Loading countdown…</p>
      </div>
      <script>
        const countdownEl = document.getElementById("countdown");
        let diff = parseInt(countdownEl.dataset.seconds);
        function formatCountdown(d) {
          const days = Math.floor(d / 86400);
          const hours = Math.floor((d % 86400) / 3600);
          const mins = Math.floor((d % 3600) / 60);
          const secs = d % 60;
          if (days > 0)
            return `${days} day${days>1?"s":""} ${hours.toString().padStart(2,"0")}:${mins.toString().padStart(2,"0")}:${secs.toString().padStart(2,"0")}`;
          else
            return `${hours.toString().padStart(2,"0")}:${mins.toString().padStart(2,"0")}:${secs.toString().padStart(2,"0")}`;
        }
        function tick() {
          if (diff > 0) { countdownEl.textContent = formatCountdown(diff); diff--; }
          else { countdownEl.textContent = "The Ultimate Quest has begun!"; clearInterval(timer); setTimeout(() => location.reload(), 2000); }
        }
        const timer = setInterval(tick, 1000);
        tick();
      </script>
    </body>
    </html>
    <?php
    exit;
}

if ($end && $now > $end) {
    ?>
    <!doctype html>
    <html<?=theme_html_attr()?>>
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <link rel="stylesheet" href="<?=BASE_URL?>/style.css?v=<?=@filemtime(__DIR__.'/style.css')?>">
      <title>The Ultimate Quest – Event Complete</title>
    </head>
    <body class="container center">
      <div class="card center">
        <h2>The Ultimate Quest has ended!</h2>
        <p>Thanks for playing — see you next time!</p>
        <p class="small">Your adventure doesn't end here… stay tuned for the next Quest!</p>
        <p class="center"><a href="<?=BASE_URL?>/logout.php">Log Out</a></p>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// Tasks LEFT JOIN this team's submissions; one row per task with status/note (or 'not_started').
$st = db()->prepare(
    "SELECT tk.id, tk.title, tk.points,
            COALESCE(s.status, 'not_started') AS status,
            s.note
       FROM tasks tk
       LEFT JOIN submissions s ON s.task_id = tk.id AND s.team_id = ?
       ORDER BY tk.sort_order, tk.id"
);
$st->execute([$team_id]);
$task_list = $st->fetchAll();
foreach ($task_list as &$t) {
    $t['id']     = (int)$t['id'];
    $t['points'] = (int)$t['points'];
}
unset($t);

usort($task_list, function ($a, $b) {
    $order = ['pending' => 1, 'rejected' => 2, 'not_started' => 3, 'approved' => 4];
    $a_o = $order[$a['status']] ?? 99;
    $b_o = $order[$b['status']] ?? 99;
    if ($a_o !== $b_o) return $a_o <=> $b_o;
    return $b['points'] <=> $a['points'];
});

// Derived scores for this team.
$ss = db()->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN s.status='approved' THEN tk.points ELSE 0 END), 0) AS awarded,
        COALESCE(SUM(CASE WHEN s.status='pending'  THEN tk.points ELSE 0 END), 0) AS pending
       FROM submissions s JOIN tasks tk ON tk.id = s.task_id
      WHERE s.team_id = ?"
);
$ss->execute([$team_id]);
$score = $ss->fetch();
$awarded = (int)$score['awarded'];
$pending = (int)$score['pending'];
$total   = $awarded + $pending;

$total_tasks    = count($task_list);
$approved_count = count(array_filter($task_list, fn($t) => $t['status'] === 'approved'));
$pending_count  = count(array_filter($task_list, fn($t) => $t['status'] === 'pending'));
$rejected_count = count(array_filter($task_list, fn($t) => $t['status'] === 'rejected'));
$progress_pct   = $total_tasks > 0 ? round(($approved_count / $total_tasks) * 100) : 0;
?>
<!doctype html>
<html<?=theme_html_attr()?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="<?=BASE_URL?>/style.css?v=<?=@filemtime(__DIR__.'/style.css')?>">
<title>Your Tasks – The Ultimate Quest</title>
</head>
<body class="container">

<h1>The Ultimate Quest</h1>
<h2>Welcome, <?=htmlspecialchars(str_replace('_',' ',$team))?>!</h2>

<div class="card center">
  <h2>Team Progress</h2>
  <div class="progress-wrapper">
    <div class="progress-bar" style="width: <?=$progress_pct?>%;"></div>
  </div>
  <p style="margin:8px 0 0;">
    ✅ <strong><?=$approved_count?></strong> Approved &nbsp;&nbsp;
    🕓 <strong><?=$pending_count?></strong> Pending &nbsp;&nbsp;
    ❌ <strong><?=$rejected_count?></strong> Rejected &nbsp;&nbsp;
    📋 <strong><?=$total_tasks?></strong> Total
  </p>
  <p><strong>Score:</strong> <?=$awarded?> pts awarded<br>
  <strong>Pending:</strong> <?=$pending?> pts<br>
  <strong>Total:</strong> <?=$total?> pts</p>
<?php if ($start && $end && $now >= $start && $now <= $end):
  $diff = $end->getTimestamp() - $now->getTimestamp(); ?>
  <p style="margin:4px 0;"><strong>Time remaining in the game:</strong></p>
  <p id="countdown-active" class="big" data-seconds="<?=$diff?>" style="margin:4px 0;">Loading remaining time…</p>
  <script>
    const countdownEl = document.getElementById("countdown-active");
    let diff = parseInt(countdownEl.dataset.seconds);
    function formatCountdown(d) {
      const days = Math.floor(d / 86400);
      const hours = Math.floor((d % 86400) / 3600);
      const mins = Math.floor((d % 3600) / 60);
      const secs = d % 60;
      if (days > 0)
        return `${days} day${days>1?"s":""} ${hours.toString().padStart(2,"0")}:${mins.toString().padStart(2,"0")}:${secs.toString().padStart(2,"0")}`;
      else
        return `${hours.toString().padStart(2,"0")}:${mins.toString().padStart(2,"0")}:${secs.toString().padStart(2,"0")}`;
    }
    function tick() {
      if (diff > 0) { countdownEl.textContent = formatCountdown(diff); diff--; }
      else { countdownEl.textContent = "Time is up!"; clearInterval(timer); setTimeout(() => location.reload(), 2000); }
    }
    const timer = setInterval(tick, 1000);
    tick();
  </script>
<?php endif; ?>
</div>

<div class="card">
  <h2>Available Tasks</h2>
  <table style="width:100%; border-collapse:collapse;">
    <tr><th class="center">ID</th><th style="text-align:left;">Task</th><th class="center">Points</th><th class="center">Status</th><th></th></tr>
    <?php foreach ($task_list as $t): ?>
      <?php
        $color = match($t['status']) {
          'approved' => 'green',
          'pending'  => 'orange',
          'rejected' => 'red',
          default    => '#555',
        };
        $label = ucfirst(str_replace('_', ' ', $t['status']));
      ?>
      <tr>
        <td class="center"><?=$t['id']?></td>
        <td><strong><?=htmlspecialchars($t['title'])?></strong></td>
        <td class="center"><?=$t['points']?></td>
        <td class="center" style="color:<?=$color?>;font-weight:600;"><?=$label?></td>
        <td class="center">
          <a href="<?=BASE_URL?>/task_submit.php?id=<?=$t['id']?>">Open</a>
        </td>
      </tr>
      <?php if ($t['status'] === 'rejected' && $t['note']): ?>
        <tr><td colspan="5" class="small" style="color:#c00;">Note: <?=htmlspecialchars($t['note'])?></td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
  </table>
</div>

<?php require __DIR__ . '/lib/theme_picker.php'; ?>

<p class="center"><a href="<?=BASE_URL?>/logout.php">Log Out</a></p>

</body>
</html>
