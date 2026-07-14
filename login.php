<?php
require_once __DIR__ . '/lib/auth.php';

$team = trim($_POST['team'] ?? '');
$pin  = trim($_POST['pin']  ?? '');
if ($team && $pin && try_team_login($team, $pin)) {
    header('Location: ' . BASE_URL . '/team.php'); exit;
}
$err = login_is_locked(normalize_team_name($team)) ? 'locked' : 'bad';
header('Location: ' . BASE_URL . '/index.php?err=' . $err);
