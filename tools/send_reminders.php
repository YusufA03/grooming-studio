<?php
// Command-line way to send the reminder emails. Use this where the host (or
// your own PC) can run a scheduled task:
//
//   php tools/send_reminders.php
//
// Run it every 5 minutes.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool can only be run from the command line.');
}

require_once __DIR__ . '/../classes/Reminder.php';

try {
    $r = Reminder::sendDue();
    echo date('Y-m-d H:i:s') . ' due=' . $r['due'] . ' sent=' . $r['sent'] . ' failed=' . $r['failed'] . ' left=' . $r['left'] . "\n";
    exit($r['failed'] > 0 ? 1 : 0);
} catch (Throwable $ex) {
    echo 'STOPPED: ' . $ex->getMessage() . "\n";
    exit(1);
}
