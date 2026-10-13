<?php
// TEMPORARY HELPER: sends one test email and explains in plain words if it fails.
//
//   http://localhost/grooming-studio/public/email_test.php?key=YOUR-SECRET&to=you@example.com
//
// It uses the same secret key as the reminder address (config/cron_config.php).
// DELETE THIS FILE from the live site once email works.
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$cronFile = __DIR__ . '/../config/cron_config.php';
$cron     = is_file($cronFile) ? require $cronFile : [];
$expected = is_array($cron) ? (string) ($cron['key'] ?? '') : '';

if (strlen($expected) < 20 || $expected === 'change-me-to-a-long-random-text') {
    http_response_code(503);
    exit("Not set up yet: create config/cron_config.php with a long secret key first.\n");
}
$given = $_GET['key'] ?? '';
if (!is_string($given) || !hash_equals($expected, $given)) {
    http_response_code(403);
    exit('Forbidden.');
}

$to = $_GET['to'] ?? '';
if (!is_string($to) || !filter_var($to, FILTER_VALIDATE_EMAIL) || strlen($to) > 150) {
    http_response_code(400);
    exit("Add the address to send to, for example:  ...email_test.php?key=YOUR-SECRET&to=you@example.com\n");
}

$mailFile = __DIR__ . '/../config/mail_config.php';
if (!is_file($mailFile)) {
    exit("config/mail_config.php is missing. Copy mail_config.example.php to that name first.\n");
}
$mail = require $mailFile;

echo "EMAIL TEST\n==========\n";
echo 'Settings in config/mail_config.php (the password is never shown):' . "\n";
echo '  host        : ' . $mail['host'] . "\n";
echo '  port        : ' . $mail['port'] . "\n";
echo '  encryption  : ' . ($mail['encryption'] === '' ? '(none)' : $mail['encryption']) . "\n";
echo '  username    : ' . ($mail['username'] === '' ? '(none)' : substr($mail['username'], 0, 3) . '***') . "\n";
echo '  password    : ' . ($mail['password'] === '' ? '(none)' : '(set)') . "\n";
echo '  from        : ' . $mail['from_email'] . "\n";
echo '  links point : ' . $mail['site_url'] . "\n\n";

$isLocal = in_array($mail['host'], ['localhost', '127.0.0.1'], true) && (int) $mail['port'] === 1025;
if ($isLocal) {
    echo "NOTE: these settings are for Mailpit, the LOCAL test mailbox. Test emails appear at\n";
    echo "http://localhost:8025 and nobody else receives them. For real clients you must switch to a real SMTP service.\n\n";
}
if (stripos($mail['site_url'], 'localhost') !== false) {
    echo "NOTE: site_url still says localhost, so the links inside emails would NOT work for a client.\n";
    echo "On the live site set it to the real web address of the public folder.\n\n";
}

require_once __DIR__ . '/../classes/Mailer.php';
echo "Sending a test email to $to ...\n\n";
$started = microtime(true);
$sent    = Mailer::sendTest($to);
$seconds = round(microtime(true) - $started, 1);

if ($sent) {
    echo "RESULT: the mail server ACCEPTED the message ($seconds s).\n";
    echo $isLocal ? "Look for it in Mailpit.\n" : "Now check the inbox of $to (and its spam folder).\n";
    exit;
}

$error = (string) Mailer::lastError();
echo "RESULT: FAILED ($seconds s)\n  The server said: $error\n\nWhat this usually means:\n";
$hints = [
    ['/authenticat|535|username and password not accepted|credentials/i',
        "The username or password was refused. Gmail: use your full Gmail address and a 16-character APP PASSWORD (no spaces), not your normal password. Brevo: use the SMTP key, not your login password."],
    ['/connect|timed out|timeout|refused|network is unreachable|getaddrinfo|resolve/i',
        "The mail server could not be reached. Check 'host' and 'port'. On InfinityFree only port 587 works. Make sure Mailpit/the provider is running or reachable."],
    ['/starttls|ssl|tls|certificate|handshake/i',
        "The encryption setting does not match the port. Port 587 needs encryption 'tls'. Port 465 needs 'ssl'. Mailpit needs '' (none)."],
    ['/sender|from address|not accepted|not authorized|not allowed|550|553|554|verify/i',
        "The server refused the 'from' address. Gmail: from_email must be the Gmail account itself. Brevo: from_email must be a sender you have verified in Brevo."],
];
$shown = false;
foreach ($hints as [$pattern, $hint]) {
    if (preg_match($pattern, $error)) {
        echo "  - $hint\n";
        $shown = true;
    }
}
if (!$shown) {
    echo "  - Compare each setting above with your email provider's SMTP instructions.\n";
}
echo "\nRemember: delete email_test.php from the live site when you have finished.\n";
