<?php
require_once __DIR__.'/../lib/auth.php';
require_admin();

$team = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['team'] ?? '');
$task = intval($_GET['task'] ?? 0);
$file = basename($_GET['f'] ?? '');

$path = DATA_DIR . "/uploads/$team/$task/$file";
if (!file_exists($path)) { http_response_code(404); exit("Not found"); }

$mime = mime_content_type($path);
header("Content-Type: $mime");
header('Content-Length: ' . filesize($path));
readfile($path);