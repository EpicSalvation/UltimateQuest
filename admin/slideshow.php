<?php
// admin/slideshow.php — gallery of everything flagged for the end-of-event
// slideshow, with per-file remove and a one-click ZIP download of the set.
require_once __DIR__ . '/../lib/auth.php';
require_admin();
require_once __DIR__ . '/../lib/thumbs.php';

date_default_timezone_set('America/New_York');

$rows = db()->query(
    "SELECT sf.id, sf.filename, sf.mime_type, sf.byte_size, sf.has_thumb,
            t.name AS team, t.display_name,
            s.task_id, tk.title AS task_title
       FROM submission_files sf
       JOIN submissions s ON s.id  = sf.submission_id
       JOIN teams t       ON t.id  = s.team_id
       JOIN tasks tk      ON tk.id = s.task_id
      WHERE sf.slideshow = 1
      ORDER BY t.name, s.task_id, sf.id"
)->fetchAll();

$total_bytes = 0;
foreach ($rows as $r) $total_bytes += (int)$r['byte_size'];

function show_bytes(int $b): string {
    if ($b >= 1024 * 1024 * 1024) return number_format($b / (1024 ** 3), 1) . ' GB';
    if ($b >= 1024 * 1024)        return number_format($b / (1024 ** 2), 1) . ' MB';
    if ($b >= 1024)               return number_format($b / 1024) . ' KB';
    return $b . ' B';
}
?>
<!doctype html>
<html<?=theme_html_attr()?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?=htmlspecialchars(csrf_token())?>">
<link rel="stylesheet" href="<?=BASE_URL?>/style.css?v=<?=@filemtime(__DIR__.'/../style.css')?>">
<title>Slideshow Picks – Admin</title>
</head>
<body class="container">
<h1>🎞 Slideshow Picks</h1>
<p class="center small"><a href="<?=BASE_URL?>/admin/dashboard.php">← Dashboard</a></p>

<div class="card center">
  <?php if (!$rows): ?>
    <p>No files marked yet. Open a submission on the
       <a href="<?=BASE_URL?>/admin/dashboard.php">dashboard</a> and tap
       <strong>“☆ Slideshow”</strong> under a photo or video to add it here.</p>
  <?php else: ?>
    <p><strong><?=count($rows)?></strong> file<?=count($rows)===1?'':'s'?> marked
       · <?=show_bytes($total_bytes)?> total</p>
    <p><a href="<?=BASE_URL?>/api/download_slideshow.php"><button type="button">⬇ Download all as ZIP</button></a></p>
    <p class="small">The ZIP unpacks into one folder per team, ready to drop into your slideshow.</p>
  <?php endif; ?>
</div>

<?php if ($rows): ?>
<div class="card">
  <div class="slideshow-grid">
    <?php foreach ($rows as $r):
      $full  = BASE_URL . '/api/file.php?team=' . rawurlencode($r['team']) . '&task=' . (int)$r['task_id'] . '&f=' . rawurlencode($r['filename']);
      $thumb = $r['has_thumb']
             ? BASE_URL . '/api/thumb.php?team=' . rawurlencode($r['team']) . '&task=' . (int)$r['task_id'] . '&f=' . rawurlencode(thumb_filename_for($r['filename']))
             : null;
      $is_video = strncmp($r['mime_type'], 'video/', 6) === 0;
      $label = ($r['display_name'] ?: str_replace('_', ' ', $r['team'])) . ' · Task ' . (int)$r['task_id'];
    ?>
    <div class="slideshow-tile" data-file-id="<?=(int)$r['id']?>">
      <?php if ($thumb): ?>
        <a href="<?=htmlspecialchars($full)?>" target="_blank" rel="noopener">
          <img src="<?=htmlspecialchars($thumb)?>" alt="" loading="lazy">
        </a>
      <?php elseif ($is_video): ?>
        <a class="slideshow-placeholder" href="<?=htmlspecialchars($full)?>" target="_blank" rel="noopener">🎬 Open video</a>
      <?php else: ?>
        <a class="slideshow-placeholder" href="<?=htmlspecialchars($full)?>" target="_blank" rel="noopener">📷 Open file</a>
      <?php endif; ?>
      <div class="small" title="<?=htmlspecialchars($r['task_title'])?>"><?=htmlspecialchars($label)?></div>
      <button type="button" class="secondary unmark-btn">✕ Remove</button>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<script>
const BASE = '<?=BASE_URL?>';
const CSRF = document.querySelector('meta[name=csrf-token]').content;

document.querySelectorAll('.unmark-btn').forEach(btn => {
  btn.addEventListener('click', async () => {
    const tile = btn.closest('.slideshow-tile');
    btn.disabled = true;
    const r = await fetch(`${BASE}/api/toggle_slideshow.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ file_id: parseInt(tile.dataset.fileId, 10), on: false }),
    });
    const j = await r.json();
    if (j.success) tile.remove();
    else { alert(j.message || 'Failed'); btn.disabled = false; }
  });
});
</script>

<p class="center"><a href="<?=BASE_URL?>/logout.php">Log Out</a></p>
</body>
</html>
