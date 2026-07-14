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
    "SELECT tk.id, tk.title, tk.points, tk.penalty, tk.mandatory,
            COALESCE(s.status, 'not_started') AS status,
            s.note
       FROM tasks tk
       LEFT JOIN submissions s ON s.task_id = tk.id AND s.team_id = ?
       ORDER BY tk.sort_order, tk.id"
);
$st->execute([$team_id]);
$task_list = $st->fetchAll();
foreach ($task_list as &$t) {
    $t['id']        = (int)$t['id'];
    $t['points']    = (int)$t['points'];
    $t['penalty']   = (int)$t['penalty'];
    $t['mandatory'] = (int)$t['mandatory'];
}
unset($t);

usort($task_list, function ($a, $b) {
    $order = ['pending' => 1, 'rejected' => 2, 'not_started' => 3, 'approved' => 4];
    $a_o = $order[$a['status']] ?? 99;
    $b_o = $order[$b['status']] ?? 99;
    if ($a_o !== $b_o) return $a_o <=> $b_o;
    return $b['points'] <=> $a['points'];
});

// Derived scores for this team. Every task's penalty counts against the team
// until that task is approved (penalties are stored positive and subtracted).
$ss = db()->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN s.status='approved' THEN tk.points  ELSE 0 END), 0) AS awarded,
        COALESCE(SUM(CASE WHEN s.status='pending'  THEN tk.points  ELSE 0 END), 0) AS pending,
        COALESCE(SUM(CASE WHEN s.status='approved' THEN tk.penalty ELSE 0 END), 0) AS penalty_cleared
       FROM submissions s JOIN tasks tk ON tk.id = s.task_id
      WHERE s.team_id = ?"
);
$ss->execute([$team_id]);
$score = $ss->fetch();
$awarded = (int)$score['awarded'];
$pending = (int)$score['pending'];

$total_penalty       = (int)db()->query('SELECT COALESCE(SUM(penalty), 0) FROM tasks')->fetchColumn();
$penalty_outstanding = $total_penalty - (int)$score['penalty_cleared'];

$total_tasks    = count($task_list);
$approved_count = count(array_filter($task_list, fn($t) => $t['status'] === 'approved'));
$pending_count  = count(array_filter($task_list, fn($t) => $t['status'] === 'pending'));
$rejected_count = count(array_filter($task_list, fn($t) => $t['status'] === 'rejected'));
$progress_pct   = $total_tasks > 0 ? round(($approved_count / $total_tasks) * 100) : 0;

$priority_total     = count(array_filter($task_list, fn($t) => $t['mandatory']));
$priority_completed = count(array_filter($task_list, fn($t) => $t['mandatory'] && $t['status'] === 'approved'));
$priority_pending   = count(array_filter($task_list, fn($t) => $t['mandatory'] && $t['status'] === 'pending'));
$priority_done      = $priority_completed >= $priority_total;

$help_phones = [];
foreach ([['help_phone', 'help_phone_name'], ['help_phone2', 'help_phone2_name']] as [$hp_key, $name_key]) {
    $raw = (string)(setting($hp_key) ?? '');
    if (trim($raw) === '') continue;
    $help_phones[] = [
        'tel'     => preg_replace('/[^0-9+]/', '', $raw),
        'display' => format_phone_display($raw),
        'name'    => trim((string)(setting($name_key) ?? '')),
    ];
}

function format_phone_display(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    $digits = preg_replace('/\D/', '', $raw);
    // US 10-digit: (NXX) NXX-XXXX
    if (strlen($digits) === 10) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
    }
    // US 11-digit with country code 1: +1 (NXX) NXX-XXXX
    if (strlen($digits) === 11 && $digits[0] === '1') {
        return sprintf('+1 (%s) %s-%s', substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7));
    }
    // 7-digit local: NXX-XXXX
    if (strlen($digits) === 7) {
        return substr($digits, 0, 3) . '-' . substr($digits, 3);
    }
    // Unknown shape (international, extension, etc.) — fall back to what was entered.
    return $raw;
}
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
  <p><strong>Score:</strong> <?=$awarded?> pts<?php if ($total_penalty > 0): ?>
    <span style="color:#c00; font-weight:600;">(−<?=$penalty_outstanding?>)</span>
  <?php endif; ?><br>
  <strong>Pending:</strong> <?=$pending?> pts</p>
  <?php if ($total_penalty > 0): ?>
  <p class="small" style="margin:4px 0 0;">⚠️ Some tasks have negative points if they are not completed —
    the amount in parentheses is being subtracted from your score until those tasks are done.</p>
  <?php endif; ?>
<?php if ($priority_total > 0): ?>
  <?php
    $prio_pct = min(100, round(($priority_completed / $priority_total) * 100));
  ?>
  <div class="mandatory-status" style="margin-top:12px; padding:10px; border-radius:8px; border:1px solid rgba(0,0,0,0.15); background:rgba(255,255,255,0.08);">
    <p style="margin:0 0 6px; font-weight:600;">⭐ Priority Tasks</p>
    <div class="progress-wrapper" style="margin:4px 0;">
      <div class="progress-bar" style="width: <?=$prio_pct?>%; background:<?=$priority_done?'#2e7d32':'#c98a00'?>;"></div>
    </div>
    <p style="margin:6px 0 0;">
      <strong><?=$priority_completed?></strong> of <strong><?=$priority_total?></strong> priority tasks completed
      <?php if ($priority_pending > 0): ?>
      &nbsp;·&nbsp;
      <span class="small"><?=$priority_pending?> pending</span>
      <?php endif; ?>
    </p>
    <p style="margin:6px 0 0; font-weight:600; color:<?=$priority_done?'#2e7d32':'#c98a00'?>;">
      <?php if ($priority_done): ?>
        ✅ All priority tasks complete!
      <?php else: ?>
        ⚠️ Skipping a priority task costs your team a steep point penalty — finish them first!
      <?php endif; ?>
    </p>
  </div>
<?php endif; ?>
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
  <?php $show_penalty_col = $total_penalty > 0; $col_count = $show_penalty_col ? 6 : 5; ?>
  <table style="width:100%; border-collapse:collapse;">
    <tr><th class="center">ID</th><th style="text-align:left;">Task</th><th class="center">Points</th><?php if ($show_penalty_col): ?><th class="center">Penalty if skipped</th><?php endif; ?><th class="center">Status</th><th></th></tr>
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
      <tr<?= $t['mandatory'] ? ' class="mandatory-row" style="background:rgba(255,193,7,0.12);"' : '' ?>>
        <td class="center"><?=$t['id']?></td>
        <td>
          <?php if ($t['mandatory']): ?>
            <span title="Priority task" style="color:#c98a00;">⭐</span>
          <?php endif; ?>
          <strong<?= $t['mandatory'] ? ' style="border-bottom:2px solid #c98a00;"' : '' ?>><?=htmlspecialchars($t['title'])?></strong>
          <?php if ($t['mandatory']): ?>
            <span class="small" style="color:#c98a00; font-weight:600;">(Priority)</span>
          <?php endif; ?>
        </td>
        <td class="center"><?=$t['points']?></td>
        <?php if ($show_penalty_col): ?>
          <td class="center" style="color:#c00; font-weight:600;"><?= $t['penalty'] > 0 ? '−' . $t['penalty'] : '' ?></td>
        <?php endif; ?>
        <td class="center" style="color:<?=$color?>;font-weight:600;"><?=$label?></td>
        <td class="center">
          <a href="<?=BASE_URL?>/task_submit.php?id=<?=$t['id']?>">Open</a>
        </td>
      </tr>
      <?php if ($t['status'] === 'rejected' && $t['note']): ?>
        <tr><td colspan="<?=$col_count?>" class="small" style="color:#c00;">Note: <?=htmlspecialchars($t['note'])?></td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
  </table>
</div>

<?php if ($help_phones): ?>
<div class="card center" style="border:2px solid #c98a00;">
  <p style="margin:0; font-weight:600;">📞 Need help?</p>
  <?php foreach ($help_phones as $hp): ?>
  <p style="margin:6px 0 0;">Call <?php if ($hp['name'] !== ''): ?><?=htmlspecialchars($hp['name'])?> at <?php endif; ?><a href="tel:<?=htmlspecialchars($hp['tel'])?>" style="font-size:1.2em; font-weight:700;"><?=htmlspecialchars($hp['display'])?></a></p>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/lib/theme_picker.php'; ?>

<p class="center"><a href="<?=BASE_URL?>/logout.php">Log Out</a></p>

</body>
</html>
