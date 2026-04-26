<?php
require_once __DIR__ . '/lib/auth.php';

$team = trim($_POST['team'] ?? '');
$pin  = trim($_POST['pin']  ?? '');
if ($team && $pin && try_team_login($team, $pin)) {
    header('Location: ' . BASE_URL . '/team.php'); exit;
}
header('Location: ' . BASE_URL . '/index.php');
