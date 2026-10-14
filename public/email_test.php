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
Mailer::enableDebug();
$started = microtime(true);
$sent    = Mailer::sendTest($to);
$seconds = round(microtime(true) - $started, 1);

// What the mail server said, with anything that could be a login removed.
function conversation(): string
{
    $lines = [];
    foreach (explode("\n", Mailer::debugLog()) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^(CLIENT -> SERVER|Client -> Server):\s*(AUTH\s+(PLAIN|LOGIN|CRAM-MD5)\s+)?[A-Za-z0-9+\/=]{12,}$/', $line)) {
            $line = preg_replace('/[A-Za-z0-9+\/=]{12,}$/', '[hidden]', $line);
        }
        $lines[] = '    ' . substr($line, 0, 170);
    }
    return implode("\n", array_slice($lines, -45));
}

if ($sent) {
    echo "RESULT: the mail server ACCEPTED the message ($seconds s).\n";
    echo "(If nothing arrives: look in the spam folder, and remember a mail server accepting a message is not the same as the inbox receiving it.)\n";
    echo $isLocal ? "Look for it in Mailpit.\n" : "Now check the inbox of $to (and its spam folder).\n";
    exit;
}

$error = (string) Mailer::lastError();
$log   = conversation();
echo "RESULT: FAILED ($seconds s)\n  The server said: $error\n\n";
if ($log !== '') {
    echo "The conversation with the mail server (logins hidden):\n$log\n\n";
}

// ---- work out the most likely reason (only ONE explanation is shown) ----
$all        = $error . "\n" . $log;
$encryption = (string) ($mail['encryption'] ?? '');

// Can we at least make a plain connection to the server? (no encryption yet)
$reached = false;
$probe   = @fsockopen((string) $mail['host'], (int) $mail['port'], $probeNo, $probeText, 5);
if ($probe) {
    $reached = true;
    fclose($probe);
}

// Did the server agree to start encryption (220 after STARTTLS)?
$startTlsAsked    = stripos($log, 'CLIENT -> SERVER: STARTTLS') !== false;
$startTlsAccepted = preg_match('/CLIENT -> SERVER: STARTTLS\s*\n\s*SERVER -> CLIENT: 220/i', $log) === 1;

$certificateProblem =
    preg_match('/certificate verify|self.signed|unable to get local issuer|ssl operation failed/i', $all) === 1
    || ($startTlsAccepted && preg_match('/could not connect|failed|crypto|tls/i', $error) === 1)
    || ($encryption === 'ssl' && $reached && preg_match('/could not connect|failed to connect/i', $error) === 1);

echo "What this usually means:\n";
if ($certificateProblem) {
    echo "  - PHP could not trust the mail server's security certificate. This is common on XAMPP, or when an antivirus 'mail shield' / 'web shield' is switched on.\n";
    echo "    Fixes: (1) turn off the antivirus's email/HTTPS scanning and try again; or\n";
    echo "           (2) download cacert.pem from https://curl.se/docs/caextract.html, save it as C:\\xampp\\php\\extras\\ssl\\cacert.pem,\n";
    echo "               add the line   openssl.cafile=\"C:\\xampp\\php\\extras\\ssl\\cacert.pem\"   to php.ini, and restart Apache.\n";
} elseif (preg_match('/authenticat|535|username and password not accepted|credentials/i', $all)) {
    echo "  - The username or password was refused. Gmail: use your full Gmail address and a 16-character APP PASSWORD (no spaces), not your normal password. Brevo: use the SMTP key, not your login password.\n";
} elseif ($reached && preg_match('/mail from|sender address|from address|not authorized|not allowed|rejected|\b55[034]\b/i', $all)) {
    echo "  - The server refused the 'from' address. Gmail: from_email must be the Gmail account itself. Brevo: from_email must be a sender you have verified in Brevo.\n";
} elseif ($startTlsAsked || ($encryption === 'tls' && $reached)) {
    echo "  - The encryption setting does not match this server. Port 587 needs encryption 'tls'. Port 465 needs 'ssl'. Mailpit needs '' (none).\n";
} elseif (!$reached || preg_match('/connect|timed out|timeout|refused|unreachable|getaddrinfo|resolve/i', $error)) {
    echo "  - The mail server could not be reached. Check 'host' and 'port'. On InfinityFree only port 587 works. Make sure Mailpit/the provider is running, and that the host allows outgoing connections on that port.\n";
} else {
    echo "  - Compare each setting above with your email provider's SMTP instructions, and read the conversation above.\n";
}
echo "\nRemember: delete email_test.php from the live site when you have finished.\n";
