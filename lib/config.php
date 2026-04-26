<?php
// lib/config.php — bootstrap: timezone, data dir, BASE_URL, DB credentials, session.

date_default_timezone_set('America/New_York');

define('DATA_DIR', '/home/thestarv/UltimateQuestData');

// DB credentials + any host-specific overrides live OUTSIDE the webroot.
// Expected file shape (dbconfig.php in DATA_DIR):
//   <?php
//   define('DB_HOST', 'localhost');
//   define('DB_NAME', 'thestarv_uquest');
//   define('DB_USER', 'thestarv_uquest');
//   define('DB_PASS', 'somepassword');
//   define('BASE_URL', '/UltimateQuest'); // optional override
$dbconfig = DATA_DIR . '/dbconfig.php';
if (is_readable($dbconfig)) {
    require_once $dbconfig;
}

if (!defined('BASE_URL')) define('BASE_URL', '/UltimateQuest');

ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

session_set_cookie_params([
    'path'     => BASE_URL . '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);

session_start();

if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0775, true); }
