<?php
require_once __DIR__ . '/../lib/auth.php';
require_admin();
require_once __DIR__ . '/../lib/thumbs.php';

date_default_timezone_set('America/New_York');

function fmt_bytes(int $b): string {
    if ($b <= 0) return '0 B';
    $u = ['B','KB','MB','GB','TB'];
    $i = (int)floor(log($b, 1024));
    $i = min($i, count($u) - 1);
    return number_format($b / (1024 ** $i), $i ? 1 : 0) . ' ' . $u[$i];
}
function pill(bool $ok, string $label): string {
    $bg = $ok ? '#1e7f3e' : '#a02020';
    return "<span style=\"display:inline-block; padding:2px 8px; border-radius:10px; color:#fff; background:$bg; font-size:0.85em;\">"
         . htmlspecialchars($label) . '</span>';
}

// ----- Environment -----
$exts = [
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'gd'        => extension_loaded('gd'),
    'imagick'   => extension_loaded('imagick'),
    'fileinfo'  => extension_loaded('fileinfo'),
    'mbstring'  => extension_loaded('mbstring'),
    'exif'      => extension_loaded('exif'),
];
$heic_ok = thumbs_can_heic();
$exif_ok = strip_exif_available();

$paths = [
    'DATA_DIR'         => DATA_DIR,
    'uploads/'         => DATA_DIR . '/uploads',
    'archive/'         => DATA_DIR . '/archive',
];
$path_status = [];
foreach ($paths as $label => $p) {
    $path_status[$label] = [
        'exists'   => is_dir($p),
        'writable' => is_dir($p) && is_writable($p),
    ];
}

// ----- Storage -----
$free  = @disk_free_space(DATA_DIR)  ?: 0;
$total = @disk_total_space(DATA_DIR) ?: 0;

$cache_file = DATA_DIR . '/.uploads_size_cache';
$uploads_bytes = null;
if (is_file($cache_file) && (time() - filemtime($cache_file) < 60)) {
    $uploads_bytes = (int)file_get_contents($cache_file);
}
if ($uploads_bytes === null) {
    $uploads_bytes = 0;
    $root = DATA_DIR . '/uploads';
    if (is_dir($root)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) if ($f->isFile()) $uploads_bytes += $f->getSize();
    }
    @file_put_contents($cache_file, (string)$uploads_bytes);
}

$pdo = db();

$largest = $pdo->query(
    "SELECT sf.filename, sf.byte_size, sf.mime_type, t.name AS team, tk.title AS task
       FROM submission_files sf
       JOIN submissions s ON s.id = sf.submission_id
       JOIN teams t       ON t.id = s.team_id
       JOIN tasks tk      ON tk.id = s.task_id
      ORDER BY sf.byte_size DESC
      LIMIT 5"
)->fetchAll();

// ----- Database -----
$db_version = $pdo->query('SELECT VERSION() AS v')->fetch()['v'] ?? 'unknown';
$db_name    = defined('DB_NAME') ? DB_NAME : 'n/a';
$counts = [];
foreach (['teams','tasks','submissions','submission_files','settings'] as $t) {
    $counts[$t] = (int)$pdo->query("SELECT COUNT(*) AS c FROM $t")->fetch()['c'];
}
$status_counts = $pdo->query(
    "SELECT status, COUNT(*) AS c FROM submissions GROUP BY status"
)->fetchAll();
$status_map = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($status_counts as $row) $status_map[$row['status']] = (int)$row['c'];

// ----- Game metrics -----
$per_team = $pdo->query(
    "SELECT t.name,
            SUM(s.status='approved') AS approved,
            SUM(s.status='pending')  AS pending,
            SUM(s.status='rejected') AS rejected,
            COALESCE(SUM(CASE WHEN s.status='approved' THEN tk.points ELSE 0 END),0) AS awarded,
            COALESCE(SUM(CASE WHEN s.status='pending'  THEN tk.points ELSE 0 END),0) AS pending_pts,
            GREATEST(COALESCE(MAX(s.submitted_at),'1970-01-01'),
                     COALESCE(MAX(s.reviewed_at), '1970-01-01')) AS last_activity
       FROM teams t
       LEFT JOIN submissions s ON s.team_id = t.id
       LEFT JOIN tasks tk      ON tk.id    = s.task_id
       GROUP BY t.id, t.name
       ORDER BY awarded DESC, t.name"
)->fetchAll();

$per_task = $pdo->query(
    "SELECT tk.id, tk.title, tk.points,
            COUNT(s.id) AS attempts,
            SUM(s.status='approved') AS approved,
            SUM(s.status='rejected') AS rejected,
            SUM(s.status='pending')  AS pending
       FROM tasks tk
       LEFT JOIN submissions s ON s.task_id = tk.id
       GROUP BY tk.id, tk.title, tk.points
       ORDER BY tk.sort_order, tk.id"
)->fetchAll();

$overall = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status='approved') AS approved,
        SUM(status='rejected') AS rejected,
        SUM(status='pending')  AS pending,
        AVG(CASE WHEN reviewed_at IS NOT NULL
                 THEN TIMESTAMPDIFF(SECOND, submitted_at, reviewed_at) END) AS avg_review_secs,
        TIMESTAMPDIFF(SECOND,
                      MIN(CASE WHEN status='pending' THEN submitted_at END),
                      NOW()) AS oldest_pending_secs
       FROM submissions"
)->fetch();

$thumb_fail = $pdo->query(
    "SELECT mime_type, COUNT(*) AS c
       FROM submission_files
      WHERE has_thumb = 0 AND mime_type LIKE 'image/%'
      GROUP BY mime_type
      ORDER BY c DESC"
)->fetchAll();

$oldest_pending_age = $overall['oldest_pending_secs'] !== null
    ? max(0, (int)$overall['oldest_pending_secs'])
    : null;

// ----- Past games -----
$past_games = $pdo->query(
    "SELECT label, ended_at, archive_path, summary_json
       FROM past_games
      ORDER BY ended_at DESC"
)->fetchAll();

// ----- Recent failures -----
$err_log = DATA_DIR . '/upload_errors.log';
$err_tail = '';
if (is_file($err_log)) {
    $lines = @file($err_log, FILE_IGNORE_NEW_LINES);
    if ($lines) $err_tail = implode("\n", array_slice($lines, -30));
}
?>
<!doctype html>
<html<?=theme_html_attr()?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="<?=BASE_URL?>/style.css?v=<?=@filemtime(__DIR__.'/../style.css')?>">
<meta http-equiv="refresh" content="15">
<title>Site &amp; Game Status – Admin</title>
<style>
  table.status { width:100%; border-collapse:collapse; }
  table.status th, table.status td { padding:6px 8px; border-bottom:1px solid rgba(0,0,0,0.08); text-align:left; }
  table.status th { background:rgba(0,0,0,0.03); }
  .num { text-align:right; font-variant-numeric:tabular-nums; }
  .kv { display:grid; grid-template-columns:max-content 1fr; gap:4px 16px; }
  .kv dt { font-weight:600; }
  .kv dd { margin:0; }
  pre.log { max-height:240px; overflow:auto; background:#111; color:#eee; padding:8px; font-size:12px; border-radius:4px; white-space:pre-wrap; }
</style>
</head>
<body class="container">
<h1>Site &amp; Game Status</h1>
<p class="center small">
  <a href="<?=BASE_URL?>/admin/dashboard.php">← Dashboard</a>
  &nbsp;·&nbsp; auto-refreshes every 15 s &nbsp;·&nbsp;
  generated <?=date('H:i:s')?> ET
</p>

<div class="card">
  <h2>Environment</h2>
  <dl class="kv">
    <dt>PHP version</dt><dd><?=PHP_VERSION?></dd>
    <dt>upload_max_filesize</dt><dd><?=ini_get('upload_max_filesize')?></dd>
    <dt>post_max_size</dt><dd><?=ini_get('post_max_size')?></dd>
    <dt>memory_limit</dt><dd><?=ini_get('memory_limit')?></dd>
    <dt>max_execution_time</dt><dd><?=ini_get('max_execution_time')?>s</dd>
    <dt>Extensions</dt><dd>
      <?php foreach ($exts as $name => $ok) echo pill($ok, $name) . ' '; ?>
    </dd>
    <dt>Imagick HEIC delegate</dt><dd><?=pill($heic_ok, $heic_ok ? 'available' : 'missing — HEIC thumbs disabled')?></dd>
    <dt>EXIF strip</dt><dd><?=pill($exif_ok, $exif_ok ? 'available — GPS metadata removed at upload' : 'missing — GPS metadata preserved')?></dd>
    <dt>Paths</dt><dd>
      <?php foreach ($path_status as $label => $s):
        $state = $s['writable'] ? 'writable' : ($s['exists'] ? 'read-only' : 'missing'); ?>
        <?=pill($s['writable'], "$label $state")?>
      <?php endforeach; ?>
    </dd>
  </dl>
</div>

<div class="card">
  <h2>Storage</h2>
  <dl class="kv">
    <dt>Disk free</dt><dd><?=fmt_bytes((int)$free)?> of <?=fmt_bytes((int)$total)?></dd>
    <dt>Uploads total</dt><dd><?=fmt_bytes((int)$uploads_bytes)?> <span class="small">(cached 60s)</span></dd>
  </dl>
  <h3>Largest 5 files</h3>
  <?php if (!$largest): ?>
    <p class="small">No files yet.</p>
  <?php else: ?>
    <table class="status">
      <tr><th>Team</th><th>Task</th><th>File</th><th>MIME</th><th class="num">Size</th></tr>
      <?php foreach ($largest as $f): ?>
        <tr>
          <td><?=htmlspecialchars($f['team'])?></td>
          <td><?=htmlspecialchars($f['task'])?></td>
          <td><?=htmlspecialchars($f['filename'])?></td>
          <td><?=htmlspecialchars($f['mime_type'])?></td>
          <td class="num"><?=fmt_bytes((int)$f['byte_size'])?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Database</h2>
  <dl class="kv">
    <dt>MySQL version</dt><dd><?=htmlspecialchars($db_version)?></dd>
    <dt>Database</dt><dd><?=htmlspecialchars($db_name)?></dd>
  </dl>
  <table class="status">
    <tr><th>Table</th><th class="num">Rows</th></tr>
    <?php foreach ($counts as $t => $c): ?>
      <tr><td><?=$t?></td><td class="num"><?=$c?></td></tr>
    <?php endforeach; ?>
  </table>
  <p style="margin-top:8px;">
    Submissions: <strong><?=$status_map['pending']?></strong> pending ·
    <strong><?=$status_map['approved']?></strong> approved ·
    <strong><?=$status_map['rejected']?></strong> rejected
  </p>
</div>

<div class="card">
  <h2>Live game metrics</h2>
  <dl class="kv">
    <dt>Total submissions</dt><dd><?=(int)$overall['total']?></dd>
    <dt>Approval rate</dt><dd>
      <?php
        $reviewed = (int)$overall['approved'] + (int)$overall['rejected'];
        echo $reviewed ? round(100 * (int)$overall['approved'] / $reviewed) . '%' : '—';
      ?>
      <span class="small">(<?=(int)$overall['approved']?> approved / <?=$reviewed?> reviewed)</span>
    </dd>
    <dt>Avg review latency</dt><dd>
      <?= $overall['avg_review_secs'] !== null ? round((float)$overall['avg_review_secs']) . 's' : '—' ?>
    </dd>
    <dt>Pending now</dt><dd>
      <?=(int)$overall['pending']?>
      <?php if ($oldest_pending_age !== null): ?>
        — oldest <strong><?=floor($oldest_pending_age / 60)?>m <?=$oldest_pending_age % 60?>s</strong>
      <?php endif; ?>
    </dd>
    <dt>Image thumbs failed</dt><dd>
      <?php if (!$thumb_fail): ?>none
      <?php else:
        foreach ($thumb_fail as $tf) echo pill(false, $tf['mime_type'] . ': ' . (int)$tf['c']) . ' ';
      endif; ?>
    </dd>
  </dl>
</div>

<div class="card">
  <h2>Per team</h2>
  <?php if (!$per_team): ?><p class="small">No teams yet.</p><?php else: ?>
    <table class="status">
      <tr><th>Team</th>
          <th class="num">✅</th><th class="num">🕓</th><th class="num">❌</th>
          <th class="num">Awarded</th><th class="num">Pending pts</th>
          <th>Last activity</th></tr>
      <?php foreach ($per_team as $r): ?>
        <tr>
          <td><?=htmlspecialchars($r['name'])?></td>
          <td class="num"><?=(int)$r['approved']?></td>
          <td class="num"><?=(int)$r['pending']?></td>
          <td class="num"><?=(int)$r['rejected']?></td>
          <td class="num"><?=(int)$r['awarded']?></td>
          <td class="num"><?=(int)$r['pending_pts']?></td>
          <td><?=$r['last_activity'] && substr($r['last_activity'],0,4)!=='1970' ? htmlspecialchars($r['last_activity']) : '—'?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Per task</h2>
  <?php if (!$per_task): ?><p class="small">No tasks yet.</p><?php else: ?>
    <table class="status">
      <tr><th>#</th><th>Task</th><th class="num">Pts</th>
          <th class="num">Attempts</th><th class="num">✅</th><th class="num">🕓</th><th class="num">❌</th>
          <th class="num">Approval rate</th></tr>
      <?php foreach ($per_task as $r):
        $reviewed = (int)$r['approved'] + (int)$r['rejected'];
        $rate = $reviewed ? round(100 * (int)$r['approved'] / $reviewed) . '%' : '—';
      ?>
        <tr>
          <td><?=(int)$r['id']?></td>
          <td><?=htmlspecialchars($r['title'])?></td>
          <td class="num"><?=(int)$r['points']?></td>
          <td class="num"><?=(int)$r['attempts']?></td>
          <td class="num"><?=(int)$r['approved']?></td>
          <td class="num"><?=(int)$r['pending']?></td>
          <td class="num"><?=(int)$r['rejected']?></td>
          <td class="num"><?=$rate?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Past games</h2>
  <?php if (!$past_games): ?>
    <p class="small">No archived games yet. Use "End game and archive…" on the dashboard after an event.</p>
  <?php else: ?>
    <table class="status">
      <tr><th>Ended</th><th>Label</th>
          <th class="num">Teams</th><th class="num">Top score</th><th>Top team</th>
          <th class="num">Files</th><th class="num">Bytes</th>
          <th>Archive</th></tr>
      <?php foreach ($past_games as $g):
        $sum = json_decode($g['summary_json'], true) ?: [];
        $team_count  = (int)($sum['team_count']  ?? 0);
        $files_count = (int)($sum['files_count'] ?? 0);
        $files_bytes = (int)($sum['files_bytes'] ?? 0);
        $top_score   = (int)($sum['top_score']   ?? 0);
        $top_team    = (string)($sum['top_team'] ?? '');
        $dl = BASE_URL . '/api/download_game_archive.php?label=' . urlencode($g['label']);
      ?>
        <tr>
          <td><?=htmlspecialchars($g['ended_at'])?></td>
          <td><?=htmlspecialchars($g['label'])?></td>
          <td class="num"><?=$team_count?></td>
          <td class="num"><?=$top_score?></td>
          <td><?=$top_team !== '' ? htmlspecialchars($top_team) : '—'?></td>
          <td class="num"><?=$files_count?></td>
          <td class="num"><?=fmt_bytes($files_bytes)?></td>
          <td><a href="<?=htmlspecialchars($dl)?>">Download ZIP</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Recent upload errors</h2>
  <?php if (!$err_tail): ?>
    <p class="small">No entries in <?=htmlspecialchars($err_log)?>.</p>
  <?php else: ?>
    <pre class="log"><?=htmlspecialchars($err_tail)?></pre>
  <?php endif; ?>
</div>

<p class="center"><a href="<?=BASE_URL?>/logout.php">Log Out</a></p>
</body>
</html>
