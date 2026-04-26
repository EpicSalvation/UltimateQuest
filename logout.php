<?php
require_once __DIR__ . '/lib/config.php';
$_SESSION = [];
session_destroy();
header('Location: ' . BASE_URL . '/index.php');
