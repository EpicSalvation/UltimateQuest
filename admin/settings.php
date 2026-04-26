<?php
// admin/settings.php — site appearance settings (theme picker for now).

require_once __DIR__ . '/../lib/auth.php';
require_admin();

$msg = '';
$err = '';

$theme_meta = [
    'quest'  => ['label' => 'Quest',  'desc' => 'Original purple → pink → blue gradient'],
    'sunset' => ['label' => 'Sunset', 'desc' => 'Warm orange → red → gold gradient'],
    'ocean'  => ['label' => 'Ocean',  'desc' => 'Cool deep blue → cyan gradient'],
    'meadow' => ['label' => 'Meadow', 'desc' => 'Forest green → fresh green gradient'],
    'light'  => ['label' => 'Light',  'desc' => 'Clean light background, no animation'],
    'dark'   => ['label' => 'Dark',   'desc' => 'Dark mode for low-light review'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $picked = (string)($_POST['theme'] ?? '');
    if (!in_array($picked, THEMES, true)) {
        $err = 'Unknown theme.';
    } else {
        set_setting('theme', $picked);
        // Also set the admin's own cookie so they immediately see the
        // change. (current_theme() prefers cookie over setting; without
        // this the admin would keep seeing whatever cookie they had.)
        $cookie_path = BASE_URL !== '' ? BASE_URL : '/';
        setcookie('theme', $picked, [
            'expires'  => time() + 31536000,
            'path'     => $cookie_path,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => false, // JS picker also writes this cookie
        ]);
        $_COOKIE['theme'] = $picked; // make the change visible on this same request
        $msg = 'Theme updated to "' . $theme_meta[$picked]['label'] . '". This is the new site default for users who haven\'t picked their own.';
    }
}

$current = current_theme();
?>
<!doctype html>
<html<?=theme_html_attr()?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="<?=BASE_URL?>/style.css?v=<?=@filemtime(__DIR__.'/../style.css')?>">
<title>Site Settings – Admin</title>
</head>
<body class="container">
<h1>Site Settings</h1>
<p class="center small">
  <a href="<?=BASE_URL?>/admin/dashboard.php">← Dashboard</a>
</p>

<?php if ($msg): ?><div class="alert success"><?=htmlspecialchars($msg)?></div><?php endif; ?>
<?php if ($err): ?><div class="alert error"><?=htmlspecialchars($err)?></div><?php endif; ?>

<div class="card">
  <h2>Theme</h2>
  <p class="small center">Pick a colour scheme for the whole site (teams, admin, leaderboard).</p>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>">
    <div class="theme-grid">
      <?php foreach ($theme_meta as $slug => $info):
        $checked = $slug === $current; ?>
        <label class="theme-card<?= $checked ? ' selected' : '' ?>">
          <div class="theme-swatch" data-preview="<?=htmlspecialchars($slug)?>"></div>
          <div>
            <input type="radio" name="theme" value="<?=htmlspecialchars($slug)?>" <?= $checked ? 'checked' : '' ?>>
            <strong><?=htmlspecialchars($info['label'])?></strong>
          </div>
          <div class="small"><?=htmlspecialchars($info['desc'])?></div>
        </label>
      <?php endforeach; ?>
    </div>
    <button type="submit">Save theme</button>
  </form>
</div>

<script>
// Visual selection state — toggles the .selected outline as the user picks.
document.querySelectorAll('.theme-card input[type="radio"]').forEach(r => {
  r.addEventListener('change', () => {
    document.querySelectorAll('.theme-card').forEach(c => c.classList.remove('selected'));
    r.closest('.theme-card').classList.add('selected');
  });
});
</script>
</body>
</html>
