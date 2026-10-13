<?php
// A secret web address that a scheduler visits every few minutes to send the
// reminder emails, for hosts that have no scheduled tasks of their own:
//
//   https://your-site/cron_reminders.php?key=THE-SECRET-FROM-config/cron_config.php
//
// Visitors without the secret key get nothing.
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$configFile = __DIR__ . '/../config/cron_config.php';
$config     = is_file($configFile) ? require $configFile : [];
$expected   = is_array($config) ? (string) ($config['key'] ?? '') : '';

// A missing, short or example key means the reminders are not set up yet.
if (strlen($expected) < 20 || $expected === 'change-me-to-a-long-random-text') {
    http_response_code(503);
    exit('Reminders are not set up.');
}

// The key must arrive as plain text (not as a list such as key[]=...).
$given = $_GET['key'] ?? '';
if (!is_string($given) || !hash_equals($expected, $given)) {
    http_response_code(403);
    exit('Forbidden.');
}

require_once __DIR__ . '/../classes/Reminder.php';

try {
    $r = Reminder::sendDue();
    echo 'OK due=' . $r['due'] . ' sent=' . $r['sent'] . ' failed=' . $r['failed'] . ' left=' . $r['left'];
} catch (Throwable $ex) {
    error_log('Reminder run failed: ' . $ex->getMessage());
    http_response_code(500);
    echo 'Error.';
}
