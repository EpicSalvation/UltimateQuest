<?php
// dbconfig.sample.php — copy this to DATA_DIR/dbconfig.php on the host.
// On the live server this file lives at:
//   /home/thestarv/UltimateQuestData/dbconfig.php
// (i.e. inside DATA_DIR, OUTSIDE the webroot — never commit the real one).
//
// On cPanel / A2 / hosting.com, DB_HOST is almost always 'localhost'.
// DB_NAME and DB_USER will be prefixed with your cPanel account name
// (e.g. 'thestarv_uquest'); use the exact strings shown on the
// "MySQL Databases" page after you create the DB and user.

define('DB_HOST', 'localhost');
define('DB_NAME', 'thestarv_uquest');
define('DB_USER', 'thestarv_uquest');
define('DB_PASS', 'replace-with-the-real-password');

// Optional. Only set this if the app is served from a path other than
// /UltimateQuest (e.g. local dev under /uq, or root-level deployment '').
// define('BASE_URL', '/UltimateQuest');
