<?php
// TEMPORARY HELPER: checks that THIS web server can run the booking system.
//
//   https://YOUR-SITE/server_check.php?key=YOUR-SECRET
//
// It uses the secret key from config/cron_config.php. It only READS things (and
// writes then deletes one tiny test file). DELETE THIS FILE when you are done.
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

// ---- session probe: logins depend on the browser sending the session cookie back ----
//   ...server_check.php?key=SECRET&session=set    then    ...server_check.php?key=SECRET&session=read
if (isset($_GET['session'])) {
    require_once __DIR__ . '/../classes/Auth.php';
    Auth::start();
    if ($_GET['session'] === 'set') {
        $_SESSION['probe'] = bin2hex(random_bytes(4));
        echo "SESSION TEST, step 1 of 2\n=========================\n";
        echo "A value was stored in your session.\n\n";
        echo "Now change the end of the address from  session=set  to  session=read  and press Enter.\n";
        echo "(Keep the same key.) You should see the same value come back.\n";
        echo "\nsession cookie settings the site uses: " . json_encode(session_get_cookie_params()) . "\n";
        exit;
    }
    echo "SESSION TEST, step 2 of 2\n=========================\n";
    if (isset($_SESSION['probe'])) {
        echo "OK: the server remembered you from the last page. Sessions (and therefore logins) work.\n";
        echo "(The stored value was " . $_SESSION['probe'] . ".)\n";
    } else {
        echo "PROBLEM: the server did NOT remember you. Staff would be sent back to the login page every time.\n\n";
        echo "Usual causes: you skipped step 1; the site is reached through two different addresses\n";
        echo "(for example http and https, or with and without a prefix); the browser blocks cookies for this site;\n";
        echo "or the session folder is not writable. Open this page again from step 1 using the exact same address.\n";
    }
    exit;
}

$problems = [];   // things that must be fixed
$notes    = [];   // things worth knowing

function row(string $status, string $what, string $detail = ''): void
{
    echo str_pad($status, 6) . $what . ($detail !== '' ? '  ->  ' . $detail : '') . "\n";
}
function section(string $title): void
{
    echo "\n" . $title . "\n" . str_repeat('-', strlen($title)) . "\n";
}

echo "SERVER CHECK for Executive Grooming Studio\n";
echo "==========================================\n";

// ---------------------------------------------------------------- PHP
section('1. PHP');
$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
row($phpOk ? 'OK' : 'FIX', 'PHP version ' . PHP_VERSION, $phpOk ? '' : 'needs PHP 8.0 or newer: choose it in the hosting panel');
if (!$phpOk) { $problems[] = 'PHP is older than 8.0'; }

foreach (['pdo_mysql' => 'talks to the database', 'mbstring' => 'text lengths', 'fileinfo' => 'checks uploaded pictures', 'openssl' => 'secure (TLS) connection to the email service'] as $ext => $why) {
    $have = extension_loaded($ext);
    row($have ? 'OK' : 'FIX', "extension $ext ($why)", $have ? '' : 'must be switched on in the hosting panel / php.ini');
    if (!$have) { $problems[] = "extension $ext is missing"; }
}
foreach (['gd' => 'shrinks and cleans pictures', 'exif' => 'turns sideways phone photos upright'] as $ext => $why) {
    $have = extension_loaded($ext);
    row($have ? 'OK' : 'note', "extension $ext ($why)", $have ? '' : 'optional: the site still works without it');
    if (!$have) { $notes[] = "extension $ext is off (optional)"; }
}
echo "\n";
echo '  upload_max_filesize : ' . ini_get('upload_max_filesize') . "   (the site accepts pictures up to 2 MB)\n";
echo '  post_max_size       : ' . ini_get('post_max_size') . "\n";
echo '  memory_limit        : ' . ini_get('memory_limit') . "\n";
echo '  max_execution_time  : ' . ini_get('max_execution_time') . " s\n";
echo '  server software     : ' . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . "\n";
echo '  secure (HTTPS)      : ' . ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'yes' : 'NO (use the https:// address; the site should be reached over HTTPS)') . "\n";
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') { $notes[] = 'this page was not opened over HTTPS'; }

// ---------------------------------------------------------------- time
section('2. Clock');
$zone = 'Africa/Johannesburg';
date_default_timezone_set($zone);
echo '  server clock (as South African time): ' . date('Y-m-d H:i:s') . "\n";
echo "  Compare this with the real time in Durban. If it is wrong, tell Claude.\n";

// ---------------------------------------------------------------- folders
section('3. Folders the site must write to');
$base = dirname(__DIR__);
$uploads = $base . '/public/uploads/services';
$target  = is_dir($uploads) ? $uploads : (is_dir($base . '/public/uploads') ? $base . '/public/uploads' : $base . '/public');
$probe   = $target . '/.write_test_' . bin2hex(random_bytes(4));
$wrote   = @file_put_contents($probe, 'x') !== false;
if ($wrote) { @unlink($probe); }
row($wrote ? 'OK' : 'FIX', 'pictures folder (' . str_replace($base . '/', '', $target) . ')', $wrote ? 'writable' : 'NOT writable: pictures cannot be uploaded; fix the folder permissions');
if (!$wrote) { $problems[] = 'the uploads folder is not writable'; }
$sess = session_save_path() ?: sys_get_temp_dir();
$photoDir = $base . '/storage/inspiration';
if (!is_dir($photoDir)) { @mkdir($photoDir, 0755, true); }
$photoProbe = $photoDir . '/.write_test_' . bin2hex(random_bytes(4));
$photoOk    = is_dir($photoDir) && @file_put_contents($photoProbe, 'x') !== false;
if ($photoOk) { @unlink($photoProbe); }
row($photoOk ? 'OK' : 'FIX', 'inspiration photos folder (storage/inspiration)', $photoOk ? 'writable' : 'NOT writable: clients cannot attach photos; create the folder storage/inspiration and make it writable');
if (!$photoOk) { $problems[] = 'the inspiration photos folder is not writable'; }
$backupDir = $base . '/backups';
if (!is_dir($backupDir)) { @mkdir($backupDir, 0700, true); }
$backupProbe = $backupDir . '/.write_test_' . bin2hex(random_bytes(4));
$backupOk    = is_dir($backupDir) && @file_put_contents($backupProbe, 'x') !== false;
if ($backupOk) { @unlink($backupProbe); }
row($backupOk ? 'OK' : 'FIX', 'backup folder (backups)', $backupOk ? 'writable' : 'NOT writable: the daily backup could not be saved; create the folder backups and make it writable');
if (!$backupOk) { $problems[] = 'the backups folder is not writable'; }
row(function_exists('gzopen') ? 'OK' : 'note', 'zlib (makes backups small)', function_exists('gzopen') ? '' : 'optional: backups are saved uncompressed instead');
row(is_writable($sess) ? 'OK' : 'FIX', 'session folder (logins need it)', is_writable($sess) ? 'writable' : 'NOT writable: staff could not log in');
if (!is_writable($sess)) { $problems[] = 'the session folder is not writable'; }
row(is_file($base . '/vendor/autoload.php') ? 'OK' : 'FIX', 'vendor/autoload.php (PHPMailer)', is_file($base . '/vendor/autoload.php') ? '' : 'the vendor folder was not uploaded');
if (!is_file($base . '/vendor/autoload.php')) { $problems[] = 'the vendor folder is missing'; }

// size of the project (free plans have a disk limit)
$bytes = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) { if ($f->isFile()) { $bytes += $f->getSize(); } }
$mb = round($bytes / 1048576, 1);
row($mb < 80 ? 'OK' : 'note', "project size: $mb MB", $mb < 80 ? 'well within a 100 MB plan' : 'getting close to a 100 MB free plan');

// ---------------------------------------------------------------- database
section('4. Database');
if (!is_file($base . '/config/db_config.php')) {
    row('FIX', 'config/db_config.php', 'missing: copy db_config.example.php and fill in the hosting panel details');
    $problems[] = 'config/db_config.php is missing';
} elseif (!extension_loaded('pdo_mysql')) {
    row('FIX', 'database', 'cannot test without the pdo_mysql extension');
} else {
    $dbc = require $base . '/config/db_config.php';
    echo "  settings being used (from config/db_config.php; the password is never shown):\n";
    echo '    host     : ' . ($dbc['host'] ?? '?') . "\n";
    echo '    database : ' . ($dbc['dbname'] ?? '?') . "\n";
    echo '    user     : ' . ($dbc['user'] ?? '?') . "\n";
    echo '    password : ' . (($dbc['pass'] ?? '') === '' ? '(EMPTY)' : '(set)') . "\n";
    $requestHost    = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    $onThisComputer = in_array($requestHost, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    if (!$onThisComputer && in_array($dbc['host'] ?? '', ['localhost', '127.0.0.1'], true)) {
        echo "    >>> The host says 'localhost'. On the live site it must be the address from the hosting panel\n";
        echo "        (Alwaysdata: mysql-YOURACCOUNT.alwaysdata.net).\n";
    }
    if (!$onThisComputer && ($dbc['user'] ?? '') === 'root') {
        echo "    >>> The user says 'root'. That is XAMPP's user. Use the database user from the hosting panel.\n";
    }
    if (!$onThisComputer && ($dbc['pass'] ?? '') === '') {
        echo "    >>> The password is empty. On the live site the database user always has a password.\n";
    }
    echo "\n";
    require_once $base . '/classes/Database.php';
    try {
        $db = Database::getConnection();
        $version = $db->query('SELECT VERSION()')->fetchColumn();
        row('OK', 'connected to the database', 'server version ' . $version);
        $tables = (int) $db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        row($tables >= 5 ? 'OK' : 'FIX', "tables found: $tables", $tables >= 5 ? '' : 'the database is empty or incomplete: import database/schema.sql in phpMyAdmin');
        if ($tables < 5) { $problems[] = 'the database has no tables yet (import schema.sql)'; }

        // Linux database servers care about capital letters in table names.
        $expectedTables = ['Administrator', 'Booking', 'Client', 'Service', 'Stylist'];
        $foundTables    = $db->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        $badCase        = [];
        foreach ($expectedTables as $want) {
            if (!in_array($want, $foundTables, true)) {
                foreach ($foundTables as $have) {
                    if (strcasecmp($have, $want) === 0) { $badCase[$have] = $want; }
                }
            }
        }
        $missingTables = array_values(array_filter($expectedTables, function ($t) use ($foundTables, $badCase) {
            return !in_array($t, $foundTables, true) && !in_array($t, $badCase, true);
        }));
        if (count($badCase) > 0) {
            row('FIX', 'table names have the WRONG CAPITAL LETTERS', 'the site needs exactly: ' . implode(', ', $expectedTables));
            foreach ($badCase as $have => $want) {
                echo "      found '$have' but the site needs '$want'.   Fix in phpMyAdmin (SQL tab):   RENAME TABLE `$have` TO `$want`;\n";
            }
            $problems[] = 'table names have the wrong capital letters (this breaks every page that reads data)';
        } elseif (count($missingTables) > 0) {
            row('FIX', 'tables missing: ' . implode(', ', $missingTables), 'import schema_hosting.sql again');
            $problems[] = 'tables are missing: ' . implode(', ', $missingTables);
        } else {
            row('OK', 'table names are exactly right (capital letters match)');
        }

        // columns that newer features need (an older schema file lacks them)
        $columnInfo = function (string $table, string $column) use ($db): ?array {
            $q = $db->prepare('SELECT is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c');
            $q->execute([':t' => $table, ':c' => $column]);
            $r = $q->fetch();
            return $r === false ? null : $r;
        };
        if (in_array('Service', $foundTables, true)) {
            $img = $columnInfo('Service', 'image');
            row($img ? 'OK' : 'FIX', 'column Service.image', $img ? '' : 'MISSING (your database came from an older schema). Run in phpMyAdmin:  ALTER TABLE Service ADD COLUMN image VARCHAR(255) NULL;');
            if (!$img) { $problems[] = 'the Service.image column is missing (the home page cannot load services)'; }
        }
        if (in_array('Client', $foundTables, true)) {
            $em = $columnInfo('Client', 'email');
            $emOk = $em && $em['is_nullable'] === 'YES';
            row($emOk ? 'OK' : 'FIX', 'column Client.email may be empty', $emOk ? '' : 'run in phpMyAdmin:  ALTER TABLE Client MODIFY email VARCHAR(150) NULL;');
            if (!$emOk) { $problems[] = 'Client.email must be allowed to be empty'; }
        }

        if ($tables >= 5 && count($badCase) === 0 && count($missingTables) === 0) {
            echo "\n  Can people actually use the site?\n";
            $services = (int) $db->query('SELECT COUNT(*) FROM Service')->fetchColumn();
            row($services > 0 ? 'OK' : 'note', "services in the database: $services", $services > 0 ? '' : 'the public page would be empty: add services on the admin Services page, or re-import schema_hosting.sql');

            $admins = (int) $db->query('SELECT COUNT(*) FROM Administrator')->fetchColumn();
            row($admins > 0 ? 'OK' : 'FIX', "administrator accounts: $admins", $admins > 0 ? '' : 'NOBODY CAN LOG IN AS ADMINISTRATOR. Create one (see the steps Claude gave you).');
            if ($admins === 0) { $problems[] = 'there is no administrator account, so nobody can log in as admin'; }

            $stylistsTotal = (int) $db->query('SELECT COUNT(*) FROM Stylist')->fetchColumn();
            $stylistsReady = (int) $db->query("SELECT COUNT(*) FROM Stylist WHERE password_hash <> 'SET_LATER'")->fetchColumn();
            row($stylistsReady > 0 ? 'OK' : 'note', "stylists who can log in: $stylistsReady of $stylistsTotal", $stylistsReady === $stylistsTotal ? '' : 'the rest have no password yet: set it on the admin Stylists page');

            // the site's OWN code reading the data (not just a raw connection)
            try {
                require_once $base . '/classes/Service.php';
                require_once $base . '/classes/Stylist.php';
                $viaApp = count(Service::getAll());
                $stylistsViaApp = count(Stylist::getAll());
                row($viaApp === $services ? 'OK' : 'FIX', "the site's own code reads $viaApp services and $stylistsViaApp stylists", $viaApp === $services ? '' : 'the app and the database disagree');
            } catch (Throwable $ex) {
                row('FIX', "the site's own code could not read the data", substr($ex->getMessage(), 0, 150));
                $problems[] = "the site's own code failed to read the data: " . substr($ex->getMessage(), 0, 100);
            }
        }
    } catch (Throwable $ex) {
        $msg = $ex->getMessage();
        row('FIX', 'could not connect to the database', substr($msg, 0, 170));
        echo "\n  What this usually means:\n";
        $shown = false;
        foreach ([
            ['/\[1049\]|unknown database/i',
                "The database NAME is wrong. Copy it exactly from the hosting panel (Alwaysdata: Databases > MySQL, for example youraccount_base)."],
            ['/\[1044\]|access denied for user .* to database/i',
                "The user and password are fine, but that user has NO RIGHTS on this database. In the hosting panel open the database user (Databases > MySQL) and give it full privileges on this database, then save."],
            ['/\[1045\]|access denied for user/i',
                "The USER or PASSWORD is wrong. Use the database user's password (not your hosting login password). The user name may not be what you expect: long account names get a short ID, so copy it from Databases > MySQL > Users."],
            ['/\[2002\]|getaddrinfo|php_network_getaddresses|connection refused|no such file or directory|name or service not known|timed out|\[2006\]/i',
                "The server could not be REACHED. The host is wrong. On Alwaysdata use mysql-YOURACCOUNT.alwaysdata.net (not localhost)."],
        ] as [$pattern, $hint]) {
            if (preg_match($pattern, $msg)) {
                echo "    - $hint\n";
                $shown = true;
                break;
            }
        }
        if (!$shown) {
            echo "    - Compare every setting above with Databases > MySQL in the hosting panel.\n";
        }
        $problems[] = 'cannot connect to the database';
    }
}

// ---------------------------------------------------------------- outgoing network
section('5. Can this server reach an email service? (outgoing connections)');
function canConnect(string $host, int $port, float $timeout = 4.0): array
{
    $start = microtime(true);
    $sock  = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $ms    = (int) round((microtime(true) - $start) * 1000);
    if ($sock) {
        fclose($sock);
        return [true, "connected in $ms ms"];
    }
    return [false, trim($errstr) !== '' ? trim($errstr) : "error $errno"];
}
$mailOk = [];
foreach ([
    ['smtp-relay.brevo.com', 587, 'Brevo, the recommended port'],
    ['smtp.gmail.com',       587, 'Gmail, the recommended port'],
    ['smtp-relay.brevo.com', 2525, 'Brevo, backup port'],
    ['smtp.gmail.com',       465, 'Gmail, the other port'],
] as [$host, $port, $label]) {
    [$ok, $detail] = canConnect($host, $port);
    row($ok ? 'OK' : 'BLOCK', "$host:$port ($label)", $detail);
    $mailOk["$host:$port"] = $ok;
}
if (!$mailOk['smtp-relay.brevo.com:587'] && !$mailOk['smtp.gmail.com:587']) {
    echo "\n  Neither Brevo nor Gmail can be reached on port 587. Emails would NOT be sent from this server.\n";
    $problems[] = 'outgoing email connections (port 587) are blocked';
} elseif (!$mailOk['smtp-relay.brevo.com:587']) {
    $notes[] = 'Brevo (587) is blocked but Gmail (587) works: use Gmail';
} elseif (!$mailOk['smtp.gmail.com:587']) {
    $notes[] = 'Gmail (587) is blocked but Brevo (587) works: use Brevo';
}

// ---------------------------------------------------------------- the site's own error log
section('6. Recent problems the site wrote to its error log');
$logFile = (string) ini_get('error_log');
if ($logFile === '' || !is_readable($logFile)) {
    echo "  The error log cannot be read from here" . ($logFile !== '' ? " ($logFile)" : '') . ".\n";
    echo "  Look for it in the hosting panel (the Logs icon), or in  admin/logs/php/php.log  in your account's files.\n";
} else {
    $size   = filesize($logFile);
    $handle = fopen($logFile, 'rb');
    fseek($handle, max(0, $size - 65536));
    $chunk  = (string) stream_get_contents($handle);
    fclose($handle);
    $shown = [];
    foreach (explode("\n", $chunk) as $logLine) {
        if (preg_match('/Could not|Login failed|failed to run|SQLSTATE|Table .* doesn.t exist|Unknown column|PHP (Fatal|Parse|Warning|Deprecated)|Reminder|email/i', $logLine)
            && stripos($logLine, 'server_check') === false) {
            $shown[] = substr(trim($logLine), 0, 260);
        }
    }
    $shown = array_slice($shown, -12);
    if (count($shown) === 0) {
        echo "  Nothing relevant has been logged recently.\n";
    } else {
        echo "  The most recent entries (newest last):\n";
        foreach ($shown as $entry) {
            echo "    $entry\n";
        }
    }
}

// ---------------------------------------------------------------- are the files the official ones?
section('7. Are all the files the official, latest versions?');
$manifestFile = $base . '/MANIFEST.json';
if (!is_file($manifestFile)) {
    echo "  MANIFEST.json is not here, so this check was skipped. (It comes with the latest bundle.)\n";
} else {
    $manifest = json_decode((string) file_get_contents($manifestFile), true);
    if (!is_array($manifest) || !isset($manifest['files'])) {
        row('FIX', 'MANIFEST.json is damaged', 'upload it again from the bundle');
        $problems[] = 'MANIFEST.json is damaged';
    } else {
        $optional = $manifest['optional'] ?? [];
        $same = 0; $different = []; $absent = [];
        foreach ($manifest['files'] as $path => $expected) {
            $full = $base . '/' . $path;
            if (!is_file($full)) {
                if (!in_array($path, $optional, true)) { $absent[] = $path; }
                continue;
            }
            $actual = hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($full)));
            if (hash_equals($expected, $actual)) { $same++; } else { $different[] = $path; }
        }
        echo '  bundle version: ' . ($manifest['version'] ?? '?') . "\n";
        row(count($different) + count($absent) === 0 ? 'OK' : 'FIX', "files matching the bundle: $same of " . count($manifest['files']));
        foreach ($different as $path) { echo "      DIFFERENT: $path   (upload the file from the bundle again)\n"; }
        foreach ($absent as $path)    { echo "      MISSING:   $path\n"; }
        if ($different || $absent) {
            $problems[] = count($different) . ' file(s) differ from the bundle and ' . count($absent) . ' are missing (see section 7)';
        }
    }
}

// ---------------------------------------------------------------- summary
echo "\n" . str_repeat('=', 50) . "\n";
if (count($problems) === 0) {
    echo "RESULT: this server can run the booking system.\n";
} else {
    echo 'RESULT: ' . count($problems) . " problem(s) to fix:\n";
    foreach ($problems as $p) { echo "  - $p\n"; }
}
foreach ($notes as $n) { echo "  (note) $n\n"; }
echo "\nSTILL CAN'T LOG IN? Test sessions: add  &session=set  to the end of this address, press Enter,\n";
echo "then change it to  &session=read  and press Enter.\n";
echo "\nDELETE server_check.php from the server when you have finished.\n";
