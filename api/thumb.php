<?php
// api/thumb.php — admin-gated thumbnail server.
require_once __DIR__ . '/../lib/auth.php';
require_admin();

$team = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['team'] ?? '');
$task = intval($_GET['task'] ?? 0);
$file = basename($_GET['f'] ?? '');

$path = DATA_DIR . "/uploads/$team/$task/thumbs/$file";
if (!is_file($path)) { http_response_code(404); exit('Not found'); }

header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
