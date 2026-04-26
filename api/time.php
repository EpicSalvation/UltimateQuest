<?php
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/dbx.php';
header('Content-Type: application/json');

$start = setting('event_start_time');
$end   = setting('event_end_time');

echo json_encode([
    'server_now' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM),
    'event'      => [
        'start_time' => $start,
        'end_time'   => $end,
    ],
]);
