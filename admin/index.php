<?php
require_once __DIR__ . '/../lib/auth.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (try_admin_login($_POST['password'] ?? '')) {
        header('Location: ' . BASE_URL . '/admin/dashboard.php'); exit;
    }
    $err = login_is_locked('__admin_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))
        ? 'Too many failed attempts — wait a minute, then try again.'
        : 'Incorrect password.';
}
?>
<!doctype html>
<html<?=theme_html_attr()?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="<?=BASE_URL?>/style.css?v=<?=@filemtime(__DIR__.'/../style.css')?>">
<title>Admin</title>
</head>
<body class="container">
  <form action="<?=BASE_URL?>/admin/index.php" method="post" class="card">
    <h2>Admin Login</h2>
    <?php if ($err): ?><div class="alert error"><?=htmlspecialchars($err)?></div><?php endif; ?>
    <label>Password <input type="password" name="password" required></label>
    <button type="submit">Enter</button>
  </form>
</body>
</html>
