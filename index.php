<?php
require_once __DIR__ . '/lib/auth.php';
if (current_team()) { header('Location: ' . BASE_URL . '/team.php'); exit; }
?>
<!doctype html>
<html<?=theme_html_attr()?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="<?=BASE_URL?>/style.css?v=<?=@filemtime(__DIR__.'/style.css')?>">
<title>The Ultimate Quest</title>
</head>
<body class="container">
  <div class="logo-wrapper">
    <img src="<?=BASE_URL?>/images/CrosswayLogo transparent.png" alt="Crossway Church Logo">
  </div>
  <h1>Youth Challenge:<br>The Ultimate Quest</h1>
  <div id="status" class="card">
    <div id="countdownLabel" class="small"></div>
    <div id="countdown" class="big">--:--:--</div>
  </div>

  <form method="post" action="<?=BASE_URL?>/login.php" class="card">
    <h2>Team Login</h2>
    <?php if (($_GET['err'] ?? '') === 'locked'): ?>
      <div class="alert error">Too many failed attempts. Wait a minute, then try again.</div>
    <?php elseif (!empty($_GET['err'])): ?>
      <div class="alert error">Login failed. Check your team name and PIN, then try again.</div>
    <?php endif; ?>
    <label>Team Name <input name="team" required autocomplete="off" autocapitalize="none"></label>
    <label>PIN <input name="pin" required inputmode="numeric" autocomplete="off"></label>
    <button type="submit">Enter</button>
  </form>

  <?php require __DIR__ . '/lib/theme_picker.php'; ?>

  <p class="center"><a href="<?=BASE_URL?>/admin/index.php">Admin</a></p>

<script>
async function sync() {
  try {
    const r = await fetch('<?=BASE_URL?>/api/time.php', { cache: 'no-store' });
    const data = await r.json();
    const now = new Date(data.server_now).getTime();
    const start = data.event && data.event.start_time ? new Date(data.event.start_time).getTime() : now;
    const end   = data.event && data.event.end_time   ? new Date(data.event.end_time).getTime()   : now;
    let label = '', target = null;
    if (now < start) { label = 'Starts in'; target = start; }
    else if (now < end) { label = 'Time remaining'; target = end; }
    else { label = 'Event finished'; target = null; }

    document.getElementById('countdownLabel').textContent = label;

    let offset = now - Date.now();
    function fmt(ms){
      if (ms < 0) ms = 0;
      const d = Math.floor(ms / 86400000);
      const h = String(Math.floor((ms % 86400000) / 3600000)).padStart(2,'0');
      const m = String(Math.floor((ms % 3600000) / 60000)).padStart(2,'0');
      const s = String(Math.floor((ms % 60000) / 1000)).padStart(2,'0');
      return (d > 0 ? `${d}d ` : '') + `${h}:${m}:${s}`;
    }
    function tick(){
      const serverNow = Date.now() + offset;
      if (!target) {
        document.getElementById('countdown').textContent = '00:00:00';
        return;
      }
      document.getElementById('countdown').textContent = fmt(target - serverNow);
    }
    tick();
    setInterval(tick, 1000);
  } catch(e) {
    document.getElementById('countdown').textContent = 'Sync error';
  }
}
sync();
</script>
</body></html>
