<?php
require_once __DIR__ . '/../lib/auth.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (try_admin_login($_POST['password'] ?? '')) {
        header('Location: ' . BASE_URL . '/admin/dashboard.php'); exit;
    }
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
    <label>Password <input type="password" name="password" required></label>
    <button type="submit">Enter</button>
  </form>
</body>
</html>
