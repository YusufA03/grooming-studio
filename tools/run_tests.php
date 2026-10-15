<?php
// AUTOMATED TEST RUNNER for Executive Grooming Studio.
//
//   C:\xampp\php\php.exe tools\run_tests.php
//
// It runs the part of the Phase 5 test cases (TC-B01, TC-S04 ...) that a program can check, on YOUR computer,
// and writes a results report (test-results\results-DATE.html and .csv) that you can screenshot and quote.
//
// HOW IT STAYS SAFE
//  - It creates its OWN test database (your database name + "_test") and deletes it afterwards. Your real
//    database is never touched.
//  - It only runs when the database host is this computer. It stops on any other host (so never on the live site).
//  - It starts its own temporary web server on port 8077 and stops it afterwards.
//  - Test emails go to Mailpit only (start Mailpit first, or the email tests are skipped). No real email is sent.
//  - Test photos go to a temporary folder, which is deleted afterwards.
//
// The checks it cannot do (looking at the screen on a phone, other browsers, people trying the site, ...) are
// listed at the end: those are the test cases you still run by hand.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool can only be run from the command line.');
}
date_default_timezone_set('Africa/Johannesburg');
set_time_limit(0);

$root = dirname(__DIR__);
foreach (['pdo_mysql', 'gd', 'mbstring', 'fileinfo', 'zlib'] as $ext) {
    if (!extension_loaded($ext)) { echo "STOPPED: the PHP extension '$ext' is not switched on.\n"; exit(1); }
}
if (!is_file($root . '/config/db_config.php') || !is_file($root . '/database/schema_hosting.sql')) {
    echo "STOPPED: config/db_config.php or database/schema_hosting.sql is missing.\n"; exit(1);
}
$cfg = require $root . '/config/db_config.php';
if (!in_array($cfg['host'], ['localhost', '127.0.0.1', '::1'], true)) {
    echo "STOPPED: the database host is '{$cfg['host']}', not this computer. The test runner only runs against a local database.\n"; exit(1);
}
if (substr($cfg['dbname'], -5) === '_test') { echo "STOPPED: the configured database already ends in _test.\n"; exit(1); }

$testDb    = $cfg['dbname'] . '_test';
$restoreDb = $cfg['dbname'] . '_test_restore';
$port      = 8077;
$base      = 'http://127.0.0.1:' . $port;
$tmp       = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gs_tests_' . bin2hex(random_bytes(4));
$storage   = $tmp . DIRECTORY_SEPARATOR . 'photos';
$backups   = $tmp . DIRECTORY_SEPARATOR . 'backups';
mkdir($storage, 0777, true);
mkdir($backups, 0777, true);
$logFile = $tmp . DIRECTORY_SEPARATOR . 'server.log';

// ============================================================ tiny framework
$results = [];     // [tc, status PASS|FAIL|SKIP, text]
$covered = [];
function t(string $tc, string $what, bool $ok, string $detail = ''): void
{
    global $results, $covered;
    $covered[$tc] = true;
    $results[] = [$tc, $ok ? 'PASS' : 'FAIL', $what . ($ok || $detail === '' ? '' : '   [' . $detail . ']')];
    echo ($ok ? '  PASS ' : '  FAIL ') . str_pad($tc, 7) . $what . ($ok || $detail === '' ? '' : '   [' . $detail . ']') . "\n";
}
function skip(string $tc, string $what, string $why): void
{
    global $results, $covered;
    $covered[$tc] = true;
    $results[] = [$tc, 'SKIP', $what . ' (' . $why . ')'];
    echo '  SKIP  ' . str_pad($tc, 7) . $what . ' (' . $why . ")\n";
}
function heading(string $s): void { echo "\n== $s ==\n"; }

final class Http
{
    private array $cookies = [];
    public array $setCookieLines = [];
    public function __construct(private string $base) {}
    public function request(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        $h = ['Connection: close'];
        if ($this->cookies) { $h[] = 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies)); }
        foreach ($headers as $x) { $h[] = $x; }
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $h), 'content' => $body ?? '',
            'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0, 'timeout' => 90]]);
        $t0   = microtime(true);
        $text = @file_get_contents($this->base . $path, false, $ctx);
        $time = microtime(true) - $t0;
        $lines = $http_response_header ?? [];
        $status = 0; $hdr = [];
        foreach ($lines as $i => $line) {
            if ($i === 0 && preg_match('#HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int) $m[1]; $hdr = []; continue; }
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int) $m[1]; $hdr = []; continue; }
            $p = strpos($line, ':');
            if ($p === false) { continue; }
            $name = strtolower(trim(substr($line, 0, $p))); $val = trim(substr($line, $p + 1));
            $hdr[$name] = $val;
            if ($name === 'set-cookie') {
                $this->setCookieLines[] = $val;
                [$pair] = explode(';', $val, 2);
                if (strpos($pair, '=') !== false) { [$k, $v] = explode('=', $pair, 2); $this->cookies[trim($k)] = trim($v); }
            }
        }
        return ['status' => $status, 'headers' => $hdr, 'body' => (string) $text, 'time' => $time];
    }
    public function get(string $path): array { return $this->request('GET', $path); }
    public function post(string $path, array $data): array
    {
        return $this->request('POST', $path, http_build_query($data), ['Content-Type: application/x-www-form-urlencoded']);
    }
    public function postFile(string $path, array $fields, string $field, string $filename, string $mime, string $bytes): array
    {
        $b = '----gs' . bin2hex(random_bytes(8)); $body = '';
        foreach ($fields as $k => $v) { $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n"; }
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"$field\"; filename=\"$filename\"\r\nContent-Type: $mime\r\n\r\n$bytes\r\n--$b--\r\n";
        return $this->request('POST', $path, $body, ['Content-Type: multipart/form-data; boundary=' . $b]);
    }
    public function csrf(string $path): string
    {
        return preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $this->get($path)['body'], $m) ? $m[1] : '';
    }
    public function login(string $email, string $password): int
    {
        $tok = $this->csrf('/login.php');
        return $this->post('/login.php', ['email' => $email, 'password' => $password, 'csrf_token' => $tok])['status'];
    }
}

function db(): PDO { global $pdo; return $pdo; }
function q(string $sql, array $p = []): array { $s = db()->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function one(string $sql, array $p = []) { $s = db()->prepare($sql); $s->execute($p); $v = $s->fetchColumn(); return $v === false ? null : $v; }
function run(string $sql, array $p = []): void { db()->prepare($sql)->execute($p); }
function decoded(string $s): string { return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function has(string $body, string $needle): bool { return stripos(decoded($body), $needle) !== false; }
function tokenFrom(array $r): ?string { return preg_match('/[a-f0-9]{64}/', $r['headers']['location'] ?? '', $m) ? $m[0] : null; }
function slotTimes(string $body): array
{
    if (!preg_match('/<ul class="slot-list">(.*?)<\/ul>/s', $body, $block)) { return []; }
    preg_match_all('/start=\d{4}-\d{2}-\d{2}(?:\+|%20| )(\d{2})(?:%3A|:)(\d{2})/i', decoded($block[1]), $m, PREG_SET_ORDER);
    return array_map(fn ($x) => $x[1] . ':' . $x[2], $m);
}
function nextWeekday(int $isoDay, int $minAhead = 9): DateTimeImmutable
{
    $d = new DateTimeImmutable('today +' . $minAhead . ' days');
    while ((int) $d->format('N') !== $isoDay) { $d = $d->modify('+1 day'); }
    return $d;
}
$slotCounter = 0;
function freshSlot(): string   // a never-used working-hours time for Thandi or Rohan (unique on any date)
{
    global $slotCounter;
    $i = $slotCounter++;
    $dayIndex = intdiv($i, 12); $d = new DateTimeImmutable('today +9 days'); $n = 0;
    while (true) {                                  // step through WORKING days only: Monday to Friday
        if ((int) $d->format('N') <= 5) { if ($n === $dayIndex) { break; } $n++; }
        $d = $d->modify('+1 day');
    }
    return $d->format('Y-m-d') . ' ' . sprintf('%02d:%02d', 9 + intdiv($i % 12, 2), ($i % 2) * 30);
}
function book(Http $h, int $service, int $stylist, string $start, string $name, ?string $email, array $extra = []): array
{
    $r = $h->post('/book_details.php', array_merge(['service_id' => $service, 'stylist_id' => $stylist, 'start' => $start,
        'name' => $name, 'email' => $email ?? '', 'phone' => '0821234567', 'notes' => '', 'allergies' => ''], $extra));
    return [$r, tokenFrom($r)];
}
function bookingIdOf(?string $token): ?string { return $token === null ? null : one('SELECT booking_id FROM Booking WHERE manage_token = ?', [$token]); }
function bookingReference(int $bookingId): string { return sprintf('EGS-%05d', $bookingId); }
function mailbox(): array { global $mailpit; return $mailpit ? (json_decode((string) @file_get_contents('http://127.0.0.1:8025/api/v1/messages'), true) ?: ['messages' => [], 'total' => 0]) : ['messages' => [], 'total' => 0]; }
function mailBody(array $m): string
{
    $d = json_decode((string) @file_get_contents('http://127.0.0.1:8025/api/v1/message/' . $m['ID']), true) ?: [];
    return decoded(($d['HTML'] ?? '') . ($d['Text'] ?? ''));
}
function phpRun(array $args, array $env = []): string
{
    global $testEnv;
    $p = proc_open(array_merge([PHP_BINARY], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge($testEnv, $env));
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($p);
    return $out;
}
function jpegBytes(int $w = 800, int $h = 600): string
{
    $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, 40, 120, 90));
    imagefilledrectangle($im, 10, 10, $w / 2, $h / 2, imagecolorallocate($im, 220, 200, 120));
    ob_start(); imagejpeg($im, null, 85); $b = ob_get_clean(); return $b;
}
function pngBytes(int $w, int $h): string { $im = imagecreatetruecolor($w, $h); ob_start(); imagepng($im); $b = ob_get_clean(); return $b; }
function gifBytes(): string { $im = imagecreate(300, 300); imagecolorallocate($im, 1, 2, 3); ob_start(); imagegif($im); $b = ob_get_clean(); return $b; }
function rrmdir(string $d): void
{
    if (!is_dir($d)) { return; }
    foreach (scandir($d) as $f) { if ($f === '.' || $f === '..') { continue; } $p = $d . DIRECTORY_SEPARATOR . $f; is_dir($p) ? rrmdir($p) : @unlink($p); }
    @rmdir($d);
}
function photoFiles(): array { global $storage; return array_values(array_filter(scandir($storage), fn ($f) => preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $f))); }

// ============================================================ set up: the TEST database and the TEST server
echo "Executive Grooming Studio - automated test runner\n";
echo str_repeat('=', 60) . "\n";
$admin = new PDO("mysql:host={$cfg['host']};charset=utf8mb4", $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
foreach ([$testDb, $restoreDb] as $name) { $admin->exec("DROP DATABASE IF EXISTS `$name`"); }
$admin->exec("CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = new PDO("mysql:host={$cfg['host']};dbname=$testDb;charset=utf8mb4", $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec((string) file_get_contents($root . '/database/schema_hosting.sql'));
echo "test database '$testDb' created (your real database '{$cfg['dbname']}' is not touched)\n";

$ADMIN_PW = 'TestAdmin#2026'; $STYLIST_PW = 'TestStylist#2026';
run('INSERT INTO Administrator (name,email,password_hash) VALUES (?,?,?)', ['Test Admin', 'test-admin@example.com', password_hash($ADMIN_PW, PASSWORD_DEFAULT)]);
foreach ([1 => 'thandi', 2 => 'rohan', 3 => 'sipho'] as $id => $nm) {
    run('UPDATE Stylist SET email = ?, password_hash = ? WHERE stylist_id = ?', ["$nm@executivegrooming.test", password_hash($STYLIST_PW, PASSWORD_DEFAULT), $id]);
}
$server = null; $cleanedUp = false;
$cleanup = function () use (&$server, &$cleanedUp, $admin, $testDb, $restoreDb, $tmp): void
{
    if ($cleanedUp) { return; }
    $cleanedUp = true;
    if (is_resource($server)) { @proc_terminate($server); @proc_close($server); }
    foreach ([$testDb, $restoreDb] as $name) { try { $admin->exec("DROP DATABASE IF EXISTS `$name`"); } catch (Throwable) { } }
    rrmdir($tmp);
};
register_shutdown_function($cleanup);
$testEnv = array_merge(getenv(), ['GS_TEST_MODE' => '1', 'GS_DB_NAME' => $testDb, 'GS_STORAGE_DIR' => $storage]);
$port = 0;
for ($p = 8077; $p <= 8099; $p++) {                      // never reuse a port that something else is already answering on
    $probe = @fsockopen('127.0.0.1', $p, $en, $es, 0.3);
    if ($probe) { fclose($probe); continue; }
    $port = $p; break;
}
if ($port === 0) { echo "STOPPED: no free port between 8077 and 8099. Close other programs and try again.\n"; exit(1); }
$base = 'http://127.0.0.1:' . $port;
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root . DIRECTORY_SEPARATOR . 'public',
    '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $logFile, '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=25M', '-d', 'memory_limit=256M'],
    [1 => ['file', $tmp . DIRECTORY_SEPARATOR . 'out.txt', 'w'], 2 => ['file', $tmp . DIRECTORY_SEPARATOR . 'err.txt', 'w']], $sp, $root, $testEnv);
$up = false;
for ($i = 0; $i < 60; $i++) {
    $sk = @fsockopen('127.0.0.1', $port, $en, $es, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
    usleep(250000);
}
$alive = is_resource($server) && (proc_get_status($server)['running'] ?? false);
if (!$up || !$alive) { echo "STOPPED: the temporary web server did not start on port $port.\n"; $cleanup(); exit(1); }
echo "temporary web server started on port $port\n";
$mailpit = (bool) @fsockopen('127.0.0.1', 8025, $en, $es, 0.5);
echo $mailpit ? "Mailpit found: the email tests will run\n" : "Mailpit NOT running: the email tests will be skipped (start Mailpit and run again)\n";
if ($mailpit) { @file_get_contents('http://127.0.0.1:8025/api/v1/messages', false, stream_context_create(['http' => ['method' => 'DELETE']])); }
$anon = new Http($base); $adm = new Http($base); $thandi = new Http($base); $rohan = new Http($base);
$loginOk = [$adm->login('test-admin@example.com', $ADMIN_PW), $thandi->login('thandi@executivegrooming.test', $STYLIST_PW), $rohan->login('rohan@executivegrooming.test', $STYLIST_PW)];

// ============================================================ B: client booking
heading('Client booking');
$r = $anon->get('/index.php'); $names = ["Men's Haircut", 'Beard Trim & Shape', 'Hair Colour', 'Deep Conditioning Treatment', 'Executive Grooming Package'];
t('TC-B01', 'the home page lists all five services with their prices', $r['status'] === 200 && count(array_filter($names, fn ($n) => has($r['body'], $n))) === 5 && has($r['body'], 'R650'));
$start = freshSlot(); [$r, $tok] = book($anon, 1, 1, $start, 'Test Tina', 'tina@example.com', ['notes' => 'Likes a short back and sides']);
$conf = $tok ? $anon->get('/booking_confirmed.php?token=' . $tok) : ['status' => 0, 'body' => ''];
t('TC-B02', 'a visitor books without any account and gets a confirmation page', $r['status'] === 302 && $conf['status'] === 200 && has($conf['body'], 'Your booking is confirmed'));
$wed = nextWeekday(3, 40); $r = $anon->get('/book.php?service_id=1&stylist_id=1&date=' . $wed->format('Y-m-d')); $times = slotTimes($r['body']);
$stepsOk = count($times) > 1; for ($i = 1; $i < count($times); $i++) { if ((strtotime($times[$i]) - strtotime($times[$i - 1])) !== 1800) { $stepsOk = false; } }
t('TC-B03', 'Thandi on a Wednesday: times run 09:00 to 16:30 in 30-minute steps (16 times)', ($times[0] ?? '') === '09:00' && end($times) === '16:30' && count($times) === 16 && $stepsOk, implode(',', array_slice($times, 0, 3)) . '...' . (string) end($times));
$mon = nextWeekday(1); $rs = $anon->get('/book.php?service_id=1&stylist_id=3&date=' . $mon->format('Y-m-d')); $ra = $anon->get('/book.php?service_id=1&stylist_id=any&date=' . $mon->format('Y-m-d'));
t('TC-B04', 'Sipho has no times on a Monday, but "any available stylist" still shows times', has($rs['body'], 'No times are available') && count(slotTimes($ra['body'])) > 0);
$wedDay = $wed->format('Y-m-d'); [$r, $tokC] = book($anon, 3, 1, "$wedDay 10:00", 'Colour Cindy', 'cindy@example.com');
$after = slotTimes($anon->get('/book.php?service_id=1&stylist_id=1&date=' . $wedDay)['body']);
t('TC-B05', 'after a 90-minute Hair Colour at 10:00 the times 10:00, 10:30 and 11:00 disappear and 11:30 is offered', $r['status'] === 302 && !array_intersect(['10:00', '10:30', '11:00'], $after) && in_array('11:30', $after, true) && in_array('09:30', $after, true));
$startDay = substr($start, 0, 10); $sched = $thandi->get('/stylist_schedule.php?view=day&date=' . $startDay); $dash = $adm->get('/admin_dashboard.php?from=' . $startDay . '&to=' . $startDay);
t('TC-B06', "the client's note shows in the stylist schedule and on the administrator dashboard", has($sched['body'], 'Likes a short back and sides') && has($dash['body'], 'Likes a short back and sides'));
$sBefore = (int) one('SELECT COUNT(*) FROM Booking'); $ph = freshSlot();
$r = $anon->postFile('/book_details.php', ['service_id' => 1, 'stylist_id' => 1, 'start' => $ph, 'name' => 'Photo Pam', 'email' => 'pam@example.com', 'phone' => '0821234567', 'notes' => '', 'allergies' => ''], 'inspiration', 'style.jpg', 'image/jpeg', jpegBytes());
$tokP = tokenFrom($r); $bidP = bookingIdOf($tokP); $phDay = substr($ph, 0, 10);
$sched = $thandi->get('/stylist_schedule.php?view=day&date=' . $phDay); $img = $bidP ? $thandi->get('/inspiration_image.php?booking_id=' . $bidP) : ['status' => 0, 'headers' => []]; $imgAnon = $bidP ? $anon->get('/inspiration_image.php?booking_id=' . $bidP) : ['status' => 0];
t('TC-B07', 'a JPG photo is accepted, shown as a thumbnail to the stylist, and opens for her (not for strangers)', $r['status'] === 302 && has($sched['body'], 'inspiration-thumb') && $img['status'] === 200 && str_starts_with($img['headers']['content-type'] ?? '', 'image/jpeg') && $imgAnon['status'] === 403);
$bad = ['SVG' => ['p.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script></svg>'], 'GIF' => ['p.gif', 'image/gif', gifBytes()],
    'text renamed .jpg' => ['p.jpg', 'image/jpeg', "just some text, not a picture\n"], 'program renamed .png' => ['p.png', 'image/png', "MZ\x90\x00\x03\x00\x00\x00" . str_repeat("\x00", 200)]];
$allRefused = true; $detail = []; $n0 = (int) one('SELECT COUNT(*) FROM Booking');
foreach ($bad as $label => [$fn, $mime, $bytes]) {
    $r = $anon->postFile('/book_details.php', ['service_id' => 1, 'stylist_id' => 1, 'start' => freshSlot(), 'name' => 'Bad Bob', 'email' => 'bob@example.com', 'phone' => '0821234567', 'notes' => '', 'allergies' => ''], 'inspiration', $fn, $mime, $bytes);
    if (!($r['status'] === 200 && (has($r['body'], 'JPG, PNG or WebP') || has($r['body'], 'not a valid')))) { $allRefused = false; $detail[] = $label; }
}
t('TC-B08', 'an SVG, a GIF, a text file renamed .jpg and a program renamed .png are all refused, and no booking is made', $allRefused && (int) one('SELECT COUNT(*) FROM Booking') === $n0, implode(',', $detail));
$big = jpegBytes() . str_repeat("\0", 9 * 1048576);
$r1 = $anon->postFile('/book_details.php', ['service_id' => 1, 'stylist_id' => 1, 'start' => freshSlot(), 'name' => 'Big Ben', 'email' => 'ben@example.com', 'phone' => '0821234567', 'notes' => '', 'allergies' => ''], 'inspiration', 'big.jpg', 'image/jpeg', $big);
$r2 = $anon->postFile('/book_details.php', ['service_id' => 1, 'stylist_id' => 1, 'start' => freshSlot(), 'name' => 'Tiny Tim', 'email' => 'tim@example.com', 'phone' => '0821234567', 'notes' => '', 'allergies' => ''], 'inspiration', 'tiny.png', 'image/png', pngBytes(50, 50));
t('TC-B09', 'a photo over 8 MB and a 50 x 50 picture are refused with the right messages', has($r1['body'], 'too big') && has($r2['body'], 'too small') && (int) one('SELECT COUNT(*) FROM Booking') === $n0);
$cases = [['name' => '', 'email' => 'a@example.com', 'phone' => '0821234567', 'notes' => '', 'want' => 'name'], ['name' => 'Val', 'email' => 'abc', 'phone' => '0821234567', 'notes' => '', 'want' => 'email'],
    ['name' => 'Val', 'email' => 'v@example.com', 'phone' => '07x', 'notes' => '', 'want' => 'phone'], ['name' => 'Val', 'email' => 'v@example.com', 'phone' => '0821234567', 'notes' => str_repeat('n', 1001), 'want' => '1000']];
$okV = true; $dV = [];
foreach ($cases as $c) {
    $r = $anon->post('/book_details.php', ['service_id' => 1, 'stylist_id' => 1, 'start' => freshSlot(), 'name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone'], 'notes' => $c['notes'], 'allergies' => '']);
    if ($r['status'] !== 200 || !preg_match('/field-error/', $r['body'])) { $okV = false; $dV[] = $c['want']; }
}
t('TC-B10', 'an empty name, a bad email, a bad phone and 1001 notes characters are each refused with a message', $okV && (int) one('SELECT COUNT(*) FROM Booking') === $n0, implode(',', $dV));
// smart scheduling
$r = $anon->get('/book.php?service_id=1'); $hasBox = has($r['body'], 'Soonest available');
preg_match('/<strong>[^<]+ at (\d\d:\d\d)<\/strong>/', decoded($r['body']), $mm); preg_match('/href="(book_details\.php\?[^"]+)"[^>]*>Book this time/', decoded($r['body']), $hm);
parse_str(parse_url($hm[1] ?? '', PHP_URL_QUERY) ?? '', $q1); $suggest = $q1['start'] ?? '';
$earliest = null;   // found independently, by looking at every day's list of times
for ($d = 0; $d < 21 && $earliest === null; $d++) {
    $day = (new DateTimeImmutable('today'))->modify("+$d days")->format('Y-m-d');
    $tt = slotTimes($anon->get("/book.php?service_id=1&stylist_id=any&date=$day")['body']);
    if ($tt) { sort($tt); $earliest = "$day {$tt[0]}"; }
}
t('TC-B13', '"Soonest available" suggests exactly the earliest time found by checking every day separately', $hasBox && $suggest !== '' && $suggest === $earliest, "suggested $suggest, earliest $earliest");
$savedBlocks = q('SELECT weekday, start_time, end_time FROM WorkingBlock WHERE stylist_id = 1'); run('DELETE FROM WorkingBlock WHERE stylist_id = 1');
$r = $anon->get('/book.php?service_id=1&stylist_id=1'); preg_match('/href="(book_details\.php\?[^"]+)"[^>]*>Book this time/', decoded($r['body']), $hm2); parse_str(parse_url($hm2[1] ?? '', PHP_URL_QUERY) ?? '', $q2);
foreach ($savedBlocks as $sb) { run('INSERT INTO WorkingBlock (stylist_id, weekday, start_time, end_time) VALUES (1, ?, ?, ?)', [$sb['weekday'], $sb['start_time'], $sb['end_time']]); }
t('TC-B14', 'a stylist who is taking bookings but has no free times: the page says so and suggests the soonest time with ANOTHER stylist', has($r['body'], 'has no free times in the next 21 days') && isset($q2['stylist_id']) && (int) $q2['stylist_id'] !== 1, 'suggested stylist ' . ($q2['stylist_id'] ?? '?'));
if ($suggest !== '') {
    [$r, $tokS] = book($anon, 1, (int) $q1['stylist_id'], $suggest, 'Suggested Sam', 'sam@example.com'); $rowS = $tokS ? q('SELECT stylist_id, DATE_FORMAT(start_time, "%Y-%m-%d %H:%i") s FROM Booking WHERE manage_token = ?', [$tokS])[0] : null;
    $next = $anon->get('/book.php?service_id=1&stylist_id=' . (int) $q1['stylist_id']); preg_match('/href="(book_details\.php\?[^"]+)"[^>]*>Book this time/', decoded($next['body']), $h3); parse_str(parse_url($h3[1] ?? '', PHP_URL_QUERY) ?? '', $q3);
    t('TC-B15', 'booking the suggested time saves it, and the next suggestion for that stylist is later', $rowS && $rowS['s'] === $suggest && (int) $rowS['stylist_id'] === (int) $q1['stylist_id'] && ($q3['start'] ?? '') > $suggest);
} else { skip('TC-B15', 'booking the suggested time', 'no suggestion was found'); }
$AL = 'Allergic to ammonia <b>x</b>'; [$r, $tokA] = book($anon, 1, 1, freshSlot(), 'Allergy Amy', 'amy@example.com', ['allergies' => $AL]); $bidA = bookingIdOf($tokA);
$confA = $tokA ? $anon->get('/booking_confirmed.php?token=' . $tokA) : ['body' => ''];
t('TC-B16', 'allergies are saved, shown to the client, and the HTML in them is shown as harmless text', $bidA && one('SELECT allergies FROM Booking WHERE booking_id = ?', [$bidA]) === $AL && has($confA['body'], 'Allergies or sensitivities you told us about') && !str_contains($confA['body'], '<b>x</b>'), 'post ' . $r['status'] . ' token ' . var_export($tokA, true) . ' msg ' . (preg_match('/(field-error|class="error")[^>]*>([^<]*)/', $r['body'], $em) ? $em[2] : ''));
$n1 = (int) one('SELECT COUNT(*) FROM Booking'); [$rL, ] = book($anon, 1, 1, freshSlot(), 'Long Larry', 'larry@example.com', ['allergies' => str_repeat('x', 501)]); [$rE, $tokE] = book($anon, 1, 1, freshSlot(), 'Exact Eve', 'eve@example.com', ['allergies' => str_repeat('y', 500)]);
t('TC-B17', '501 allergy characters are refused (no booking); exactly 500 are accepted', $rL['status'] === 200 && has($rL['body'], '500 characters') && (int) one('SELECT COUNT(*) FROM Booking') === $n1 + 1 && $tokE !== null);
// loyalty on the client's page
$pc = (int) one('SELECT client_id FROM Booking WHERE booking_id = ?', [$bidA]);
$past = (new DateTimeImmutable('now'))->modify('-3 hours'); run("INSERT INTO Booking (client_id,stylist_id,service_id,start_time,end_time,status,price_charged,manage_token) VALUES (?,1,1,?,?,'confirmed',150,?)", [$pc, $past->format('Y-m-d H:i:s'), $past->modify('+30 minutes')->format('Y-m-d H:i:s'), str_repeat('a', 64)]);
$pastId = (int) one('SELECT booking_id FROM Booking WHERE manage_token = ?', [str_repeat('a', 64)]);
$out = $thandi->post('/stylist_schedule.php', ['csrf_token' => $thandi->csrf('/stylist_schedule.php'), 'booking_id' => $pastId, 'outcome' => 'completed']); $msg = $thandi->get('/stylist_schedule.php')['body']; $cp = $anon->get('/booking_confirmed.php?token=' . str_repeat('a', 64));
t('TC-B18', 'a completed R150 appointment shows "Your loyalty points: 15" on the client page', has($cp['body'], 'Your loyalty points: 15'));
t('TC-S10', 'marking it completed tells the stylist the client earned 15 points; marking it again is refused with no extra points',
    has($msg, 'earned 15 loyalty points') && (int) one('SELECT COALESCE(SUM(points),0) FROM LoyaltyLedger WHERE client_id = ?', [$pc]) === 15
    && $thandi->post('/stylist_schedule.php', ['csrf_token' => $thandi->csrf('/stylist_schedule.php'), 'booking_id' => $pastId, 'outcome' => 'completed'])['status'] === 302 && (int) one('SELECT COALESCE(SUM(points),0) FROM LoyaltyLedger WHERE client_id = ?', [$pc]) === 15);

// ============================================================ the true race tests (several separate PHP processes at once)
heading('Two or more people at the same instant (real parallel processes)');
$worker = $tmp . DIRECTORY_SEPARATOR . 'worker.php';
file_put_contents($worker, '<?php
require $argv[1] . "/classes/Booking.php"; require_once $argv[1] . "/classes/Stylist.php"; require_once $argv[1] . "/classes/Service.php"; require_once $argv[1] . "/classes/Loyalty.php";
$go = $argv[2]; while (!file_exists($go)) { usleep(300); }
if ($argv[3] === "book") {
    $st = null; foreach (Stylist::getAll() as $s) { if ((int) $s["stylist_id"] === 1) { $st = $s; } }
    $tok = Booking::create($st, Service::find(1), new DateTimeImmutable($argv[4]), "Racer " . getmypid(), "racer" . getmypid() . "@example.com", null, null);
    echo $tok === null ? "TAKEN" : "OK";
} else { echo Loyalty::redeem((int) $argv[4], 1, "race", "worker") === "" ? "OK" : "REFUSED"; }
');
function race(string $mode, string $arg, int $n): array
{
    global $worker, $root, $testEnv, $tmp;
    $go = $tmp . DIRECTORY_SEPARATOR . 'go_' . bin2hex(random_bytes(3)); $procs = [];
    for ($i = 0; $i < $n; $i++) { $procs[] = proc_open([PHP_BINARY, $worker, $root, $go, $mode, $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, null, $testEnv); $pipesAll[$i] = $pp; }
    usleep(1500000); file_put_contents($go, '1');
    $outs = [];
    foreach ($procs as $i => $p) { $outs[] = trim(stream_get_contents($pipesAll[$i][1])); proc_close($p); }
    @unlink($go);
    return $outs;
}
$raceSlot = freshSlot(); $outs = race('book', $raceSlot, 6);
t('TC-B12', 'six people confirm the same time at the same instant: exactly one is saved, five are told it was taken', count(array_keys($outs, 'OK', true)) === 1 && count(array_keys($outs, 'TAKEN', true)) === 5 && (int) one('SELECT COUNT(*) FROM Booking WHERE stylist_id = 1 AND start_time = ?', [$raceSlot . ':00']) === 1, implode(',', $outs));
t('TC-I02', 'the same race result is confirmed in the database (never two bookings at one time)', (int) one('SELECT COUNT(*) FROM Booking a JOIN Booking b ON a.stylist_id = b.stylist_id AND a.booking_id < b.booking_id AND a.status <> "cancelled" AND b.status <> "cancelled" AND a.start_time < b.end_time AND a.end_time > b.start_time WHERE a.start_time > NOW() + INTERVAL 1 DAY') === 0);
run('INSERT INTO Client (name,email) VALUES ("Race Rob","rob@example.com")'); $rc = (int) db()->lastInsertId();
run("INSERT INTO LoyaltyLedger (client_id,booking_id,points,reason,note,actor,created_at) VALUES (?,NULL,100,'adjustment','seed','test',NOW())", [$rc]);
$outs = race('redeem', (string) $rc, 4);
t('TC-A14', 'four administrators spend the same 100 points at once: exactly one reward is recorded and the balance is 0, never negative',
    count(array_keys($outs, 'OK', true)) === 1 && (int) one('SELECT COALESCE(SUM(points),0) FROM LoyaltyLedger WHERE client_id = ?', [$rc]) === 0, implode(',', $outs));

// ============================================================ E: email and reminders
heading('Email and reminders (needs Mailpit)');
if ($mailpit) {
    @file_get_contents('http://127.0.0.1:8025/api/v1/messages', false, stream_context_create(['http' => ['method' => 'DELETE']]));
    [$r, $tokM] = book($anon, 1, 2, freshSlot(), 'Mail Mike', 'mike@example.com', ['allergies' => 'Latex gloves']); usleep(500000); $mb = mailbox(); $mm0 = $mb['messages'][0] ?? null; $body = $mm0 ? mailBody($mm0) : '';
    t('TC-E01', 'a confirmation email arrives with the service, the stylist, a link and the allergies', $mm0 !== null && str_contains($body, "Men's Haircut") && str_contains($body, 'Latex gloves') && str_contains($body, 'booking_confirmed.php?token=' . $tokM));
    @file_get_contents('http://127.0.0.1:8025/api/v1/messages', false, stream_context_create(['http' => ['method' => 'DELETE']]));
    run('INSERT INTO Client (name,email) VALUES ("Remind Rita","rita@example.com")'); $rid = (int) db()->lastInsertId(); $soon = (new DateTimeImmutable('now'))->modify('+30 minutes');
    run("INSERT INTO Booking (client_id,stylist_id,service_id,start_time,end_time,status,price_charged,manage_token) VALUES (?,1,1,?,?,'confirmed',150,?)", [$rid, $soon->format('Y-m-d H:i:s'), $soon->modify('+30 minutes')->format('Y-m-d H:i:s'), str_repeat('b', 64)]);
    phpRun([$root . '/tools/send_reminders.php']); usleep(500000); $n1m = (int) (mailbox()['total'] ?? 0); phpRun([$root . '/tools/send_reminders.php']); usleep(500000); $n2m = (int) (mailbox()['total'] ?? 0);
    t('TC-E02', 'a reminder is emailed for an appointment under an hour away, once and never twice', $n1m === 1 && $n2m === 1, "after run 1: $n1m, after run 2: $n2m");
    @file_get_contents('http://127.0.0.1:8025/api/v1/messages', false, stream_context_create(['http' => ['method' => 'DELETE']]));
    $s0 = freshSlot(); $day0 = substr($s0, 0, 10); $addPage = $thandi->get('/stylist_add_booking.php?service_id=1&date=' . $day0 . '&start=' . urlencode($s0));
    preg_match('/<form method="post" action="stylist_add_booking.php".*?<\/form>/s', $addPage['body'], $fm); preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)"/', $fm[0] ?? '', $hf, PREG_SET_ORDER);
    $post = ['client_name' => 'Phone Pat', 'client_phone' => '0831112222', 'client_email' => '', 'notes' => '', 'allergies' => '']; foreach ($hf as $x) { $post[$x[1]] = $x[2]; }
    $r = $thandi->post('/stylist_add_booking.php', $post); usleep(400000);
    t('TC-E03', 'a phone booking with no email is saved, no email is attempted, and the dashboard says "(no email)"', $r['status'] === 302 && (int) (mailbox()['total'] ?? 0) === 0 && has($adm->get('/admin_dashboard.php?from=' . $day0 . '&to=' . $day0)['body'], '(no email)'));
    [$r, $tokR] = book($anon, 1, 2, freshSlot(), 'Move Mo', 'mo@example.com'); @file_get_contents('http://127.0.0.1:8025/api/v1/messages', false, stream_context_create(['http' => ['method' => 'DELETE']]));
    $anon->post('/reschedule.php', ['token' => $tokR, 'new_start' => freshSlot()]); usleep(500000); $subj1 = mailbox()['messages'][0]['Subject'] ?? '';
    @file_get_contents('http://127.0.0.1:8025/api/v1/messages', false, stream_context_create(['http' => ['method' => 'DELETE']]));
    $anon->post('/cancel_booking.php', ['token' => $tokR]); usleep(500000); $subj2 = mailbox()['messages'][0]['Subject'] ?? '';
    t('TC-E05', 'rescheduling and cancelling from the private link each send their own email', stripos($subj1, 'reschedul') !== false && stripos($subj2, 'cancel') !== false, "$subj1 | $subj2");
    [$r, $tokK] = book($anon, 1, 2, freshSlot(), 'Cancel Kim', 'kim@example.com'); @file_get_contents('http://127.0.0.1:8025/api/v1/messages', false, stream_context_create(['http' => ['method' => 'DELETE']]));
    $dd = substr((string) one('SELECT start_time FROM Booking WHERE manage_token = ?', [$tokK]), 0, 10); $dp = $adm->get('/admin_dashboard.php?from=' . $dd . '&to=' . $dd);
    $adm->post('/admin_dashboard.php', ['csrf_token' => $adm->csrf('/admin_dashboard.php'), 'booking_id' => bookingIdOf($tokK)]); usleep(500000);
    t('TC-E06', 'the administrator cancelling a booking emails the client', one('SELECT status FROM Booking WHERE manage_token = ?', [$tokK]) === 'cancelled' && stripos(mailbox()['messages'][0]['Subject'] ?? '', 'cancel') !== false);
} else {
    foreach (['TC-E01' => 'confirmation email', 'TC-E02' => 'reminder email', 'TC-E03' => 'no email for a phone booking', 'TC-E05' => 'reschedule and cancel emails', 'TC-E06' => 'administrator cancel email'] as $tc => $w) { skip($tc, $w, 'start Mailpit and run again'); }
}

// ============================================================ M: change and cancel
heading('Changing and cancelling');
[$r, $tokM1] = book($anon, 1, 1, freshSlot(), 'Change Chad', 'chad@example.com'); $oldStart = substr((string) one('SELECT start_time FROM Booking WHERE manage_token = ?', [$tokM1]), 0, 16); $newStart = freshSlot();
$r = $anon->post('/reschedule.php', ['token' => $tokM1, 'new_start' => $newStart]); $row = q('SELECT DATE_FORMAT(start_time,"%Y-%m-%d %H:%i") s, DATE_FORMAT(end_time,"%H:%i") e, status FROM Booking WHERE manage_token = ?', [$tokM1])[0];
$dayOld = substr($oldStart, 0, 10); $oldBack = in_array(substr($oldStart, 11), slotTimes($anon->get("/book.php?service_id=1&stylist_id=1&date=$dayOld")['body']), true);
t('TC-M01', 'rescheduling from the link moves the booking, recalculates the end, keeps it confirmed, and frees the old time', $r['status'] === 302 && $row['s'] === $newStart && $row['e'] === date('H:i', strtotime($newStart) + 1800) && $row['status'] === 'confirmed' && $oldBack);
$r = $anon->post('/cancel_booking.php', ['token' => $tokM1]); $stat = one('SELECT status FROM Booking WHERE manage_token = ?', [$tokM1]); $dayNew = substr($newStart, 0, 10);
t('TC-M02', 'cancelling from the link marks it cancelled and frees the time', $stat === 'cancelled' && in_array(substr($newStart, 11), slotTimes($anon->get("/book.php?service_id=1&stylist_id=1&date=$dayNew")['body']), true));
$mkc = function (int $minutes, string $tok) { global $rid; $s = (new DateTimeImmutable('now'))->modify("+$minutes minutes"); run("INSERT INTO Client (name,email) VALUES (?,?)", ['Cut ' . $minutes, "cut$minutes@example.com"]); $c = (int) db()->lastInsertId();
    run("INSERT INTO Booking (client_id,stylist_id,service_id,start_time,end_time,status,price_charged,manage_token) VALUES (?,2,1,?,?,'confirmed',150,?)", [$c, $s->format('Y-m-d H:i:s'), $s->modify('+30 minutes')->format('Y-m-d H:i:s'), $tok]); };
$mkc(100, str_repeat('c', 64)); $mkc(140, str_repeat('d', 64));
$pg119 = $anon->get('/reschedule.php?token=' . str_repeat('c', 64)); $pg121 = $anon->get('/reschedule.php?token=' . str_repeat('d', 64));
t('TC-M03', 'inside 2 hours the client cannot change the booking online; outside 2 hours the change page opens', (has($pg119['body'], '2 hours') || has($pg119['body'], 'no longer be changed')) && !preg_match('/class="slot-list"/', $pg119['body']) && !has($pg121['body'], 'no longer be changed') && (has($pg121['body'], 'Choose a new day') || has($pg121['body'], 'slot')));
$pw = $anon->get('/booking_confirmed.php?token=' . str_repeat('9', 64));
t('TC-M04', 'a wrong private link says "We could not find that booking" and shows nothing', has($pw['body'], 'could not find that booking') && !has($pw['body'], 'is confirmed') && !has($pw['body'], 'Reschedule'), 'status ' . $pw['status']);
$pc2 = $anon->get('/booking_confirmed.php?token=' . $tokM1); $pc3 = $anon->get('/reschedule.php?token=' . $tokM1);
t('TC-M05', 'the link of a cancelled booking shows its status and offers no change', has($pc2['body'], 'cancelled') && !has($pc2['body'], 'Reschedule') && !preg_match('/class="slot-list"/', $pc3['body']));

// ============================================================ S: stylist portal
heading('Stylist portal');
$wr = new Http($base); $un = new Http($base);
$r1 = $wr->post('/login.php', ['email' => 'thandi@executivegrooming.test', 'password' => 'wrong-password', 'csrf_token' => $wr->csrf('/login.php')]); $r2 = $un->post('/login.php', ['email' => 'nobody@example.com', 'password' => 'whatever12345', 'csrf_token' => $un->csrf('/login.php')]);
$m1 = preg_match('/class="error">([^<]*)/', $r1['body'], $a1) ? trim($a1[1]) : 'x'; $m2 = preg_match('/class="error">([^<]*)/', $r2['body'], $a2) ? trim($a2[1]) : 'y';
t('TC-S01', 'a stylist logs in and reaches her own schedule', $loginOk[1] === 302 && has($thandi->get('/stylist_schedule.php')['body'], '(stylist)') && !has($thandi->get('/stylist_schedule.php')['body'], 'admin_dashboard.php'));
t('TC-S02', 'a wrong password and an unknown email give the SAME message (nothing reveals which was wrong)', $m1 === $m2 && $m1 !== '' && $m1 !== 'x', "$m1 | $m2");
$mine = $thandi->get('/stylist_schedule.php?view=day&date=' . $phDay); $theirs = $rohan->get('/stylist_schedule.php?view=day&date=' . $phDay);
t('TC-S03', "a stylist sees her own bookings with notes and photo, and never another stylist's", has($mine['body'], 'Photo Pam') && !has($theirs['body'], 'Photo Pam'));
$fut = (int) one('SELECT booking_id FROM Booking WHERE manage_token = ?', [$tokS ?? $tokP]); $rf = $thandi->post('/stylist_schedule.php', ['csrf_token' => $thandi->csrf('/stylist_schedule.php'), 'booking_id' => $bidP, 'outcome' => 'no-show']);
$flash = $thandi->get('/stylist_schedule.php')['body'];
t('TC-S04', 'an appointment that has not started cannot be marked as a NO-SHOW (message: only after it has started; Completed is allowed, see TC-S15)', has($flash, 'after the appointment has started') && one('SELECT status FROM Booking WHERE booking_id = ?', [$bidP]) === 'confirmed');
run("INSERT INTO Booking (client_id,stylist_id,service_id,start_time,end_time,status,price_charged,manage_token) VALUES (?,1,1,?,?,'confirmed',150,?)", [$pc, $past->modify('-2 hours')->format('Y-m-d H:i:s'), $past->modify('-90 minutes')->format('Y-m-d H:i:s'), str_repeat('e', 64)]); $nsId = (int) one('SELECT booking_id FROM Booking WHERE manage_token = ?', [str_repeat('e', 64)]);
$thandi->post('/stylist_schedule.php', ['csrf_token' => $thandi->csrf('/stylist_schedule.php'), 'booking_id' => $nsId, 'outcome' => 'no-show']); $again = $thandi->post('/stylist_schedule.php', ['csrf_token' => $thandi->csrf('/stylist_schedule.php'), 'booking_id' => $nsId, 'outcome' => 'completed']);
t('TC-S05', 'a started appointment can be marked no-show, and the outcome is then final', one('SELECT status FROM Booking WHERE booking_id = ?', [$nsId]) === 'no-show');
$ra = $thandi->get('/admin_dashboard.php'); $rb = $thandi->get('/admin_reports.php');
t('TC-S06', 'a stylist opening an administrator page gets a "no access" answer (403)', $ra['status'] === 403 && $rb['status'] === 403);
$s7 = freshSlot(); $d7 = substr($s7, 0, 10); $ap = $thandi->get('/stylist_add_booking.php?service_id=1&date=' . $d7 . '&start=' . urlencode($s7)); preg_match('/<form method="post" action="stylist_add_booking.php".*?<\/form>/s', $ap['body'], $fm2); preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)"/', $fm2[0] ?? '', $hf2, PREG_SET_ORDER);
$base7 = ['notes' => '', 'allergies' => '', 'client_email' => '']; foreach ($hf2 as $x) { $base7[$x[1]] = $x[2]; }
$noC = $thandi->post('/stylist_add_booking.php', $base7 + ['client_name' => 'No Contact', 'client_phone' => '']); $okP = $thandi->post('/stylist_add_booking.php', $base7 + ['client_name' => 'Walk Wendy', 'client_phone' => '0839990000']);
t('TC-S07', 'a phone booking is saved with a name and phone; with neither phone nor email it asks for one', $okP['status'] === 302 && $noC['status'] === 200 && has($noC['body'], 'phone number or an email'), "save:{$okP['status']} nocontact:{$noC['status']} message: " . (preg_match_all('/class="(?:field-error|error)">([^<]*)/', $noC['body'], $e1) ? implode(' / ', $e1[1]) : 'none found'));
$ten = new Http($base); $ten->login('thandi@executivegrooming.test', $STYLIST_PW); $ten->post('/logout.php', ['csrf_token' => $ten->csrf('/stylist_schedule.php')]); $after2 = $ten->get('/stylist_schedule.php');
t('TC-S08', 'after logging out, staff pages send the visitor to the login page (the 30-minute idle timeout is tested by hand)', $after2['status'] === 302 && str_contains($after2['headers']['location'] ?? '', 'login'));
$sa = $thandi->get('/stylist_schedule.php?view=day&date=' . substr((string) one('SELECT start_time FROM Booking WHERE booking_id = ?', [$bidA]), 0, 10)); $sb = $rohan->get('/stylist_schedule.php?view=day&date=' . substr((string) one('SELECT start_time FROM Booking WHERE booking_id = ?', [$bidA]), 0, 10));
t('TC-S09', "allergies show in a highlighted box for the booked stylist and not for another stylist", str_contains($sa['body'], 'allergy-alert') && has($sa['body'], 'Allergic to ammonia') && !has($sb['body'], 'Allergic to ammonia'));

// ============================================================ A: administrator
heading('Administrator portal');
$da = $adm->get('/admin_dashboard.php?from=' . $phDay . '&to=' . $phDay);
t('TC-A01', 'the administrator logs in and the dashboard lists bookings with client, stylist, service and status', $loginOk[0] === 302 && $da['status'] === 200 && has($da['body'], 'Photo Pam') && has($da['body'], 'confirmed'));
$allB = (int) one('SELECT COUNT(*) FROM Booking WHERE status = "cancelled"'); $dc = $adm->get('/admin_dashboard.php?from=2000-01-01&to=2100-01-01&status=cancelled&q=&stylist_id=');
$onlyC = substr_count($dc['body'], '<td>cancelled</td>') === $allB && substr_count($dc['body'], '<td>confirmed</td>') === 0;
t('TC-A02', 'the dashboard filter by status shows only that status', $onlyC && has($adm->get('/admin_dashboard.php?from=' . $phDay . '&to=' . $phDay . '&q=Photo')['body'], 'Photo Pam'));
$t0 = $adm->csrf('/admin_services.php'); $svc = $adm->post('/admin_services.php', ['csrf_token' => $t0, 'action' => 'add', 'name' => 'Kids Cut', 'description' => 'For children', 'duration_minutes' => '30', 'price' => '120']);
t('TC-A03', 'a new service "Kids Cut" is added and appears on the public home page at R120', has($anon->get('/index.php')['body'], 'Kids Cut') && has($anon->get('/index.php')['body'], 'R120'));
$badSvc = [['Dur4', '4', '100'], ['Dur481', '481', '100'], ['Price0', '30', '0'], ['PriceNeg', '30', '-5'], ['PriceHuge', '30', '1234567'], ["Men's Haircut", '30', '100']]; $okS = true; $nS = (int) one('SELECT COUNT(*) FROM Service');
foreach ($badSvc as [$nm, $du, $pr]) { $adm->post('/admin_services.php', ['csrf_token' => $adm->csrf('/admin_services.php'), 'action' => 'add', 'name' => $nm, 'description' => '', 'duration_minutes' => $du, 'price' => $pr]); }
t('TC-A04', 'durations 4 and 481, prices 0, -5 and 1234567, and a duplicate name are all refused; nothing is saved', (int) one('SELECT COUNT(*) FROM Service') === $nS);
$kc = (int) one('SELECT service_id FROM Service WHERE name = "Kids Cut"'); $adm->post('/admin_services.php', ['csrf_token' => $adm->csrf('/admin_services.php'), 'action' => 'delete', 'service_id' => 1]);
$adm->post('/admin_services.php', ['csrf_token' => $adm->csrf('/admin_services.php'), 'action' => 'delete', 'service_id' => $kc]);
t('TC-A05', 'deleting a service that has bookings is refused; deleting an unused one works', (int) one('SELECT COUNT(*) FROM Service WHERE service_id = 1') === 1 && (int) one('SELECT COUNT(*) FROM Service WHERE service_id = ?', [$kc]) === 0);
$ns = (int) one('SELECT COUNT(*) FROM Stylist');
$addSty = fn (string $name, string $email, string $pw, array $svcs) => $adm->post('/admin_stylists.php', ['csrf_token' => $adm->csrf('/admin_stylists.php'), 'action' => 'add', 'name' => $name, 'email' => $email, 'first_day' => 'Mon', 'last_day' => 'Fri', 'open_time' => '09:00', 'close_time' => '17:00', 'password' => $pw, 'services' => $svcs]);
$addSty('Short Pass', 'short@executivegrooming.test', '123456789', ['1', '2']);
$short = (int) one('SELECT COUNT(*) FROM Stylist') === $ns;
$addSty('Long Pass', 'long@executivegrooming.test', '1234567890', ['1', '2']);
$lp = new Http($base);
$detA6 = 'short refused:' . var_export($short, true) . ' count:' . (int) one('SELECT COUNT(*) FROM Stylist') . '/' . $ns;
t('TC-A06', 'a 9-character stylist password is refused; a 10-character one is accepted and can log in; only a hash is stored', $short && (int) one('SELECT COUNT(*) FROM Stylist') === $ns + 1 && $lp->login('long@executivegrooming.test', '1234567890') === 302 && str_starts_with((string) one('SELECT password_hash FROM Stylist WHERE email = "long@executivegrooming.test"'), '$2y$'), $detA6);
foreach (q('SELECT block_id FROM WorkingBlock WHERE stylist_id = 2') as $bk) { $adm->post('/admin_stylists.php', ['csrf_token' => $adm->csrf('/admin_stylists.php'), 'action' => 'remove_block', 'stylist_id' => 2, 'block_id' => $bk['block_id']]); }
foreach ([1, 2, 3] as $dd) { $adm->post('/admin_stylists.php', ['csrf_token' => $adm->csrf('/admin_stylists.php'), 'action' => 'add_block', 'stylist_id' => 2, 'day' => $dd, 'from' => '10:00', 'to' => '14:00']); }
$rt = slotTimes($anon->get('/book.php?service_id=1&stylist_id=2&date=' . nextWeekday(2, 40)->format('Y-m-d'))['body']); $rth = slotTimes($anon->get('/book.php?service_id=1&stylist_id=2&date=' . nextWeekday(4, 40)->format('Y-m-d'))['body']);
t('TC-A07', "changing a stylist's hours to Mon-Wed 10:00-14:00 changes the times offered (and Thursday offers none)", $rt && $rt[0] === '10:00' && end($rt) === '13:30' && count($rth) === 0, implode(',', array_slice($rt, 0, 2)) . '..' . (string) end($rt));
run('DELETE FROM WorkingBlock WHERE stylist_id = 2'); foreach ([1, 2, 3, 4, 5] as $dd) { run("INSERT INTO WorkingBlock (stylist_id, weekday, start_time, end_time) VALUES (2, ?, '09:00:00', '17:00:00')", [$dd]); }
[$r, $tokAd] = book($anon, 1, 1, freshSlot(), 'Admin Cancel', 'ac@example.com'); $bidAd = bookingIdOf($tokAd); $dAd = substr((string) one('SELECT start_time FROM Booking WHERE booking_id = ?', [$bidAd]), 0, 10);
$adm->post('/admin_dashboard.php', ['csrf_token' => $adm->csrf('/admin_dashboard.php'), 'booking_id' => $bidAd]);
t('TC-A08', 'the administrator cancels a future booking: status cancelled and the time is free again', one('SELECT status FROM Booking WHERE booking_id = ?', [$bidAd]) === 'cancelled', 'status ' . (string) one('SELECT status FROM Booking WHERE booking_id = ?', [$bidAd]) . ', booking ' . (string) $bidAd);
[$r, $tokRs] = book($anon, 1, 1, freshSlot(), 'Admin Move', 'am@example.com'); $bidRs = bookingIdOf($tokRs); $toStart = freshSlot(); [$r, $tokBlock] = book($anon, 1, 1, $toStart, 'Block Bea', 'bb@example.com');
$pgR = $adm->get('/admin_reschedule.php?booking_id=' . $bidRs); preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $pgR['body'], $cm);
$taken = $adm->post('/admin_reschedule.php', ['csrf_token' => $cm[1] ?? '', 'booking_id' => $bidRs, 'new_start' => $toStart]); $freeS = freshSlot(); $moved = $adm->post('/admin_reschedule.php', ['csrf_token' => $cm[1] ?? '', 'booking_id' => $bidRs, 'new_start' => $freeS]);
t('TC-A09', 'the administrator moves a booking to a free time; a taken time is refused', $taken['status'] === 200 && $moved['status'] === 302 && substr((string) one('SELECT start_time FROM Booking WHERE booking_id = ?', [$bidRs]), 0, 16) === $freeS, "taken:{$taken['status']} moved:{$moved['status']} msg: " . (preg_match_all('/class="(?:field-error|error)">([^<]*)/', $moved['body'], $e2) ? implode(' / ', $e2[1]) : 'none') . " now " . (string) one('SELECT start_time FROM Booking WHERE booking_id = ?', [$bidRs]) . " wanted $freeS");
$s10 = freshSlot(); $d10 = substr($s10, 0, 10); $ap2 = $adm->get('/stylist_add_booking.php?stylist_id=2&service_id=1&date=' . $d10 . '&start=' . urlencode($s10)); preg_match('/<form method="post" action="stylist_add_booking.php".*?<\/form>/s', $ap2['body'], $fm3); preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)"/', $fm3[0] ?? '', $hf3, PREG_SET_ORDER);
$b10 = ['notes' => '', 'allergies' => '', 'client_email' => '', 'client_name' => 'Admin Adam', 'client_phone' => '0831114444']; foreach ($hf3 as $x) { $b10[$x[1]] = $x[2]; } $ra10 = $adm->post('/stylist_add_booking.php', $b10);
t('TC-A10', "the administrator adds a booking in Rohan's diary and it shows in his schedule", $ra10['status'] === 302 && has($rohan->get('/stylist_schedule.php?view=day&date=' . $d10)['body'], 'Admin Adam'), 'post status ' . $ra10['status']);
$hist = $adm->get('/admin_booking.php?booking_id=' . $bidRs);
t('TC-A11', "a booking's History page lists who booked it and who moved it, in order", has($hist['body'], 'Booked') && has($hist['body'], 'Rescheduled') && has($hist['body'], 'Client (online)') && has($hist['body'], 'Administrator: Test Admin') && strpos($hist['body'], 'Booked') < strpos($hist['body'], 'Rescheduled'), 'status ' . $hist['status']);
t('TC-A12', 'History is closed to visitors (login page) and to a stylist who does not own the booking (404), and unknown bookings give 404', $rohan->get('/admin_booking.php?booking_id=' . $bidRs)['status'] === 404 && $anon->get('/admin_booking.php?booking_id=' . $bidRs)['status'] === 302 && $adm->get('/admin_booking.php?booking_id=999999')['status'] === 404);
$lyl = $adm->get('/admin_loyalty.php?q=' . urlencode('Alice')); run('INSERT INTO Client (name,email,phone) VALUES ("Alice Loyal","alice@example.com","082 123 4567")'); $aid = (int) db()->lastInsertId();
$f1 = has($adm->get('/admin_loyalty.php?q=alice')['body'], 'Alice Loyal'); $f2 = has($adm->get('/admin_loyalty.php?q=alice%40example')['body'], 'Alice Loyal'); $f3 = has($adm->get('/admin_loyalty.php?q=0821234')['body'], 'Alice Loyal'); $f4 = has($adm->get('/admin_loyalty.php?q=%25')['body'], 'Alice Loyal');
t('TC-A13', 'the loyalty page finds a client by name, by email and by phone typed without spaces (a % in the search is literal)', $f1 && $f2 && $f3 && !$f4);
run("INSERT INTO LoyaltyLedger (client_id,booking_id,points,reason,note,actor,created_at) VALUES (?,NULL,150,'adjustment','seed','test',NOW())", [$aid]);
$lp1 = fn (string $action, array $f) => $adm->post('/admin_loyalty.php', array_merge(['csrf_token' => $adm->csrf('/admin_loyalty.php?client_id=' . $aid), 'action' => $action, 'client_id' => $aid], $f));
$lp1('redeem', ['blocks' => '1', 'note' => 'R50 off']); $b1 = (int) one('SELECT SUM(points) FROM LoyaltyLedger WHERE client_id = ?', [$aid]); $lp1('redeem', ['blocks' => '1', 'note' => '']); $b2 = (int) one('SELECT SUM(points) FROM LoyaltyLedger WHERE client_id = ?', [$aid]);
$lp1('adjust', ['points' => '20', 'note' => 'Goodwill']); $b3 = (int) one('SELECT SUM(points) FROM LoyaltyLedger WHERE client_id = ?', [$aid]); $lp1('adjust', ['points' => '-999', 'note' => 'too much']); $b4 = (int) one('SELECT SUM(points) FROM LoyaltyLedger WHERE client_id = ?', [$aid]);
t('TC-A14b', 'redeem 1 reward (150 to 50), a second is refused, +20 works, and -999 is refused (never below zero)', [$b1, $b2, $b3, $b4] === [50, 50, 70, 70], implode(',', [$b1, $b2, $b3, $b4]));
// reports against a known dataset
heading('Reports checked against a known dataset');
run('INSERT INTO Client (name,email) VALUES ("Data Dave","dave@example.com")'); $dave = (int) db()->lastInsertId(); mt_srand(2026);
$SV = [1 => [30, 150.0], 2 => [20, 90.0], 3 => [90, 650.0], 4 => [45, 350.0], 5 => [90, 550.0]]; $rows = [];
for ($i = 0; $i < 60; $i++) { $st = new DateTimeImmutable(sprintf('2026-09-%02d %02d:%02d:00', mt_rand(1, 30), mt_rand(9, 16), mt_rand(0, 1) * 30)); $sid = mt_rand(1, 2); $sv = mt_rand(1, 5);
    $stat = ['completed', 'completed', 'completed', 'completed', 'completed', 'completed', 'no-show', 'cancelled', 'cancelled', 'confirmed', 'confirmed', 'confirmed'][mt_rand(0, 11)]; $rows[] = [$sid, $sv, $st, $stat, $SV[$sv][1] + [0, 0, 10, -10][mt_rand(0, 3)]]; }
$rows[] = [1, 1, new DateTimeImmutable('2026-09-01 00:00:00'), 'completed', 150.0]; $rows[] = [2, 2, new DateTimeImmutable('2026-09-30 23:30:00'), 'completed', 90.0];
$rows[] = [1, 1, new DateTimeImmutable('2026-08-31 23:30:00'), 'completed', 150.0]; $rows[] = [2, 2, new DateTimeImmutable('2026-10-01 00:00:00'), 'completed', 90.0];
foreach ($rows as $i => [$sid, $sv, $st, $stat, $pr]) { run('INSERT INTO Booking (client_id,stylist_id,service_id,start_time,end_time,status,price_charged,manage_token) VALUES (?,?,?,?,?,?,?,?)', [$dave, $sid, $sv, $st->format('Y-m-d H:i:s'), $st->modify('+' . $SV[$sv][0] . ' minutes')->format('Y-m-d H:i:s'), $stat, $pr, hash('sha256', "rep$i")]); }
$P0 = new DateTimeImmutable('2026-09-01'); $P1 = new DateTimeImmutable('2026-10-01'); $inp = array_values(array_filter($rows, fn ($r) => $r[2] >= $P0 && $r[2] < $P1));
$cnt = fn (string $s) => count(array_filter($inp, fn ($r) => $r[3] === $s)); $rev = array_sum(array_map(fn ($r) => $r[4], array_filter($inp, fn ($r) => $r[3] === 'completed')));
$act = array_values(array_filter($inp, fn ($r) => $r[3] !== 'cancelled')); $wd = array_fill(0, 7, 0); foreach ($act as $r) { $wd[(int) $r[2]->format('N') - 1]++; }
$csvR = $adm->get('/admin_report_overview.php?from=2026-09-01&to=2026-09-30&export=csv'); $lines = array_map('str_getcsv', preg_split('/\r?\n/', ltrim($csvR['body'], "\xEF\xBB\xBF")));
$sect = function (string $head) use ($lines): array { $o = []; $on = false; foreach ($lines as $l) { if ($on) { if ($l === [null] || $l === []) { break; } $o[] = $l; } elseif (($l[0] ?? '') === $head) { $on = true; } } return $o; };
$sum = []; foreach ($sect('SUMMARY') as $l) { $sum[$l[0]] = $l[1] ?? ''; } $wdCsv = array_map(fn ($l) => (int) $l[1], $sect('BUSY DAYS (bookings that were not cancelled)'));
$svcCsv = []; foreach ($sect('SERVICES') as $l) { $svcCsv[$l[0]] = [(int) $l[1], (int) $l[2], round((float) $l[3], 2)]; }
$expSvc = []; foreach ($SV as $id => [$d, $p]) { $nm = one('SELECT name FROM Service WHERE service_id = ?', [$id]); $m = array_values(array_filter($act, fn ($r) => $r[1] === $id)); $expSvc[$nm] = [count($m), count(array_filter($m, fn ($r) => $r[3] === 'completed')), round(array_sum(array_map(fn ($r) => $r[4], array_filter($m, fn ($r) => $r[3] === 'completed'))), 2)]; }
t('TC-A15', 'the report figures (bookings, completed, no-shows, cancelled, upcoming, revenue) match an independent count of 60+4 known bookings', (int) ($sum['Bookings'] ?? -1) === count($inp) && (int) ($sum['Completed'] ?? -1) === $cnt('completed') && (int) ($sum['No-shows'] ?? -1) === $cnt('no-show') && (int) ($sum['Cancelled'] ?? -1) === $cnt('cancelled') && (int) ($sum['Upcoming (confirmed)'] ?? -1) === $cnt('confirmed') && abs((float) ($sum['Revenue from completed (R)'] ?? -1) - $rev) < 0.01);
t('TC-A15b', 'busy days and popular services match the independent count; the boundary rows (midnight on 1 Sept, 23:30 on 30 Sept) are counted and the days either side are not', $wdCsv === $wd && $svcCsv == $expSvc);
$pageR = $adm->get('/admin_report_overview.php?from=2026-09-01&to=2026-09-30'); one('SELECT 1'); run('UPDATE Service SET name = ? WHERE service_id = 2', ['=HYPERLINK("http://x","y")']); $csv2 = $adm->get('/admin_report_overview.php?from=2026-09-01&to=2026-09-30&export=csv'); run("UPDATE Service SET name = 'Beard Trim & Shape' WHERE service_id = 2");
t('TC-A16', 'the CSV download opens as a spreadsheet file and a formula-looking service name is defused', str_starts_with($csvR['headers']['content-type'] ?? '', 'text/csv') && str_contains($csvR['headers']['content-disposition'] ?? '', '2026-09-01-to-2026-09-30') && str_contains($csv2['body'], "'=HYPERLINK") && has($pageR['body'], 'Stylist performance'));
$bads = ['from=2026-09-30&to=2026-09-01' => 'must not be after', 'from=2026-02-30&to=2026-03-01' => 'real dates', 'from=2020-01-01&to=2026-09-01' => 'one year or less']; $okR = true;
foreach ($bads as $qs => $want) { if (!has($adm->get('/admin_report_overview.php?' . $qs)['body'], $want)) { $okR = false; } }
t('TC-A17', 'a reversed period, 30 February and a period over a year are each refused with a message', $okR);

// ============================================================ R: the five management reports
heading('The five management reports (checked against the same known dataset)');
$pr = 'from=2026-09-01&to=2026-09-30';
$csvLines = function (string $path) use ($adm): array { $r = $adm->get($path); return array_map('str_getcsv', preg_split('/\r?\n/', ltrim($r['body'], "\xEF\xBB\xBF"))); };
$csvSection = function (array $lines, string $head): array { $o = []; $on = false; foreach ($lines as $l) { if ($on) { if ($l === [null] || $l === [] || ($l[0] ?? '') === '') { break; } $o[] = $l; } elseif (($l[0] ?? '') === $head) { $on = true; } } return $o; };
$csvValue = function (array $lines, string $key) { foreach ($lines as $l) { if (($l[0] ?? null) === $key) { return $l; } } return null; };
$idx = $adm->get('/admin_reports.php')['body'];
t('TC-R01', 'the Reports page lists the five reports and the overview, each with an Open button, and says allergy notes never appear', substr_count($idx, '>Open<') === 6 && has($idx, 'Bookings register') && has($idx, 'Takings') && has($idx, 'Stylist activity') && has($idx, 'Cancellations and no-shows') && has($idx, 'Customer history') && has($idx, 'Allergy and sensitivity notes never appear'));
$reg = $csvLines("/admin_report_register.php?$pr&export=csv"); $regRows = array_values(array_filter($reg, fn ($l) => str_starts_with($l[0] ?? '', 'EGS-')));
$cnt2 = fn (string $st) => count(array_filter($inp, fn ($r) => $r[3] === $st));
$totalsOk = (int) ($csvValue($reg, 'Appointments')[1] ?? -1) === count($inp) && (int) ($csvValue($reg, 'Completed')[1] ?? -1) === $cnt2('completed') && (int) ($csvValue($reg, 'No-shows')[1] ?? -1) === $cnt2('no-show') && (int) ($csvValue($reg, 'Cancelled')[1] ?? -1) === $cnt2('cancelled');
$byS1 = $csvLines("/admin_report_register.php?$pr&stylist_id=1&export=csv"); $byC = $csvLines("/admin_report_register.php?$pr&status=cancelled&export=csv"); $refQ = $regRows[3][0] ?? 'EGS-00000';
$byRef = $csvLines("/admin_report_register.php?$pr&q=" . urlencode($refQ) . "&export=csv"); $byPct = $csvLines("/admin_report_register.php?$pr&q=%25&export=csv");
$isRef = fn ($l) => str_starts_with($l[0] ?? '', 'EGS-');
t('TC-R02', 'the Bookings register lists exactly the bookings starting in the period, with correct totals, and the stylist, status, reference and search filters work (a % in the search is literal)',
    count($regRows) === count($inp) && $totalsOk && count(array_filter($byS1, $isRef)) === count(array_filter($inp, fn ($r) => $r[0] === 1)) && count(array_filter($byC, $isRef)) === $cnt2('cancelled') && count(array_filter($byRef, $isRef)) === 1 && count(array_filter($byPct, $isRef)) === 0, 'rows ' . count($regRows) . ' of ' . count($inp));
$tk = $csvLines("/admin_report_takings.php?$pr&export=csv"); $dayRows = array_filter($csvSection($tk, 'DAY BY DAY'), fn ($l) => ($l[0] ?? '') !== 'Date' && ($l[0] ?? '') !== 'Total'); $svcRows = array_filter($csvSection($tk, 'SERVICE BY SERVICE'), fn ($l) => ($l[0] ?? '') !== 'Service' && ($l[0] ?? '') !== 'Total');
$revNow = round(array_sum(array_map(fn ($r) => $r[4], array_filter($inp, fn ($r) => $r[3] === 'completed'))), 2);
$rewPts = (int) one("SELECT COALESCE(SUM(-points),0) FROM LoyaltyLedger WHERE reason = 'redeemed' AND created_at >= '2026-09-01' AND created_at < '2026-10-01'"); $rewVal = intdiv($rewPts, 100) * 50;
t('TC-R03', 'Takings: the day-by-day and service-by-service tables each add up to the value of the completed appointments, and the loyalty rewards in the period are taken off to give the till figure',
    abs(array_sum(array_map(fn ($l) => (float) $l[2], $dayRows)) - $revNow) < 0.01 && abs(array_sum(array_map(fn ($l) => (float) $l[2], $svcRows)) - $revNow) < 0.01 && abs((float) ($csvValue($tk, 'Takings less rewards (R)')[1] ?? -1) - ($revNow - $rewVal)) < 0.01, "revenue $revNow");
$sa = $csvLines("/admin_report_stylists.php?$pr&export=csv"); $saBy = []; foreach ($sa as $l) { if (isset($l[0]) && in_array($l[0], ['Thandi Mokoena', 'Rohan Naidoo', 'Sipho Dlamini'], true)) { $saBy[$l[0]] = $l; } }
$rosterMin = function (int $sid): int { $mins = []; foreach (q('SELECT weekday, TIME_TO_SEC(TIMEDIFF(end_time, start_time)) / 60 AS m FROM WorkingBlock WHERE stylist_id = ?', [$sid]) as $b) { $mins[(int) $b['weekday']] = ($mins[(int) $b['weekday']] ?? 0) + (int) $b['m']; } $tot = 0; for ($d = new DateTimeImmutable('2026-09-01'); $d->format('m') === '09'; $d = $d->modify('+1 day')) { $tot += $mins[(int) $d->format('N')] ?? 0; } return $tot; };
$saOk = true; $saDet = '';
foreach ([1 => 'Thandi Mokoena', 2 => 'Rohan Naidoo', 3 => 'Sipho Dlamini'] as $sid => $nm) {
    $mine = array_filter($inp, fn ($r) => $r[0] === $sid); $booked = array_sum(array_map(fn ($r) => $SV[$r[1]][0], array_filter($mine, fn ($r) => in_array($r[3], ['confirmed', 'completed', 'no-show'], true))));
    $x = $saBy[$nm] ?? null; $okRow = $x !== null && abs((float) $x[1] - round($rosterMin($sid) / 60, 1)) < 0.06 && abs((float) $x[2] - round($booked / 60, 1)) < 0.06 && (int) $x[4] === count(array_filter($mine, fn ($r) => $r[3] === 'completed')) && (int) $x[6] === count(array_filter($mine, fn ($r) => $r[3] === 'cancelled'));
    if (!$okRow) { $saOk = false; $saDet .= "$nm "; }
}
t('TC-R04', 'Stylist activity: hours rostered (from the working blocks), hours booked, completed and cancelled are right for every stylist, including one with no bookings', $saOk, $saDet);
$cancelled = q("SELECT booking_id, start_time FROM Booking WHERE client_id = ? AND status = 'cancelled' AND start_time >= '2026-09-01' AND start_time < '2026-10-01' ORDER BY booking_id LIMIT 2", [$dave]);
$noticeWant = [90, 1500]; foreach ($cancelled as $i => $c) { run("INSERT INTO BookingLog (booking_id, action, actor, detail, created_at) VALUES (?, 'cancelled', 'Client (private link)', 'x', ?)", [$c['booking_id'], (new DateTimeImmutable($c['start_time']))->modify('-' . $noticeWant[$i] . ' minutes')->format('Y-m-d H:i:s')]); }
$cc = $csvLines("/admin_report_cancellations.php?$pr&export=csv"); $ccRows = array_values(array_filter($csvSection($cc, 'CANCELLATIONS'), fn ($l) => str_starts_with($l[0] ?? '', 'EGS-'))); $ccBy = []; foreach ($ccRows as $l) { $ccBy[(int) substr($l[0], 4)] = $l; }
$ccPage = $adm->get("/admin_report_cancellations.php?$pr")['body'];
t('TC-R05', 'Cancellations and no-shows: every cancelled appointment is listed, the notice given is worked out from the booking history (90 and 1500 minutes), "not recorded" is blank, and a cancellation under 2 hours counts as late',
    count($ccRows) === $cnt2('cancelled') && (int) ($ccBy[(int) $cancelled[0]['booking_id']][8] ?? -1) === 90 && (int) ($ccBy[(int) $cancelled[1]['booking_id']][8] ?? -1) === 1500 && count(array_filter($ccRows, fn ($l) => ($l[8] ?? '') === '')) === $cnt2('cancelled') - 2
    && preg_match('/<span class="kpi-value">1<\/span><span class="kpi-label">Cancelled with under 2 hours notice/', $ccPage) === 1 && count(array_filter($csvSection($cc, 'NO-SHOWS'), fn ($l) => str_starts_with($l[0] ?? '', 'EGS-'))) === $cnt2('no-show'));
$ch = $csvLines("/admin_report_customer.php?client_id=$dave&export=csv");
$allRows = q('SELECT status, price_charged FROM Booking WHERE client_id = ?', [$dave]);
t('TC-R06', 'Customer history: one client\'s appointments, completed visits, total spent, no-shows and cancellations are right, the visit list is capped at the latest 50, and an unknown client is a 404',
    (int) ($csvValue($ch, 'Appointments')[1] ?? -1) === count($allRows) && (int) ($csvValue($ch, 'Completed')[1] ?? -1) === count(array_filter($allRows, fn ($r) => $r['status'] === 'completed')) && abs((float) ($csvValue($ch, 'Total spent (R)')[1] ?? -1) - round(array_sum(array_map(fn ($r) => (float) $r['price_charged'], array_filter($allRows, fn ($r) => $r['status'] === 'completed'))), 2)) < 0.01
    && count(array_filter($csvSection($ch, 'VISITS (latest 50)'), fn ($l) => str_starts_with($l[0] ?? '', 'EGS-'))) === min(50, count($allRows)) && $adm->get('/admin_report_customer.php?client_id=999999')['status'] === 404);
run("UPDATE Booking SET allergies = 'SECRETALLERGY' WHERE client_id = ?", [$dave]);
$everything = ''; foreach (['/admin_report_register.php', '/admin_report_takings.php', '/admin_report_stylists.php', '/admin_report_cancellations.php', '/admin_report_overview.php'] as $rp) { $everything .= $adm->get("$rp?$pr")['body'] . $adm->get("$rp?$pr&export=csv")['body']; }
$everything .= $adm->get("/admin_report_customer.php?client_id=$dave")['body'] . $adm->get("/admin_report_customer.php?client_id=$dave&export=csv")['body'];
t('TC-R07', 'ALLERGY NOTES never appear on any report page or in any report download (every one of the client\'s bookings has an allergy note)', !str_contains($everything, 'SECRETALLERGY'));
run('UPDATE Client SET name = ? WHERE client_id = ?', ['=HYPERLINK("http://x","y")', $dave]); run("INSERT INTO LoyaltyLedger (client_id, booking_id, points, reason, note, actor, created_at) VALUES (?, NULL, -100, 'redeemed', 'r', 't', NOW())", [$dave]);
$regCsv = $adm->get("/admin_report_register.php?$pr&export=csv")['body']; $custCsv = $adm->get("/admin_report_customer.php?client_id=$dave&export=csv")['body'];
t('TC-R08', 'CSV safety: a client name that looks like a spreadsheet formula is defused, a real negative number (-100 points) is left as a number, and the files download as CSV', str_contains($regCsv, "'=HYPERLINK") && str_contains($custCsv, ',-100,') && !str_contains($custCsv, "'-100") && str_starts_with($adm->get("/admin_report_takings.php?$pr&export=csv")['headers']['content-type'] ?? '', 'text/csv'));
run('UPDATE Client SET name = ? WHERE client_id = ?', ['Data Dave', $dave]);
$periodOk = true; foreach (['/admin_report_register.php', '/admin_report_takings.php', '/admin_report_stylists.php', '/admin_report_cancellations.php'] as $rp) { foreach (['from=2026-09-30&to=2026-09-01' => 'must not be after', 'from=2026-02-30&to=2026-03-01' => 'real dates', 'from=2020-01-01&to=2026-09-01' => 'one year or less'] as $qs => $want) { if (!has($adm->get("$rp?$qs")['body'], $want)) { $periodOk = false; } } if ($adm->get("$rp?from[]=1&to[]=2&q[]=x&export[]=csv")['status'] !== 200) { $periodOk = false; } }
t('TC-R09', 'every report refuses a reversed period, 30 February and a period over a year with a message, and array parameters do not crash it', $periodOk);

// ============================================================ I: integrity
heading('Integrity');
$q1ok = false; try { db()->exec('INSERT INTO Service (name,duration_minutes,price) VALUES ("Zero",30,0)'); } catch (Throwable) { $q1ok = true; }
$q2ok = false; try { db()->exec("INSERT INTO Booking (client_id,stylist_id,service_id,start_time,end_time,manage_token) VALUES ($dave,1,1,'2026-12-01 10:00:00','2026-12-01 09:00:00','" . str_repeat('z', 64) . "')"); } catch (Throwable) { $q2ok = true; }
$q3ok = false; try { db()->exec("INSERT INTO Booking (client_id,stylist_id,service_id,start_time,end_time,manage_token) VALUES (999999,1,1,'2026-12-01 10:00:00','2026-12-01 11:00:00','" . str_repeat('y', 64) . "')"); } catch (Throwable) { $q3ok = true; }
$q4ok = false; try { db()->exec('SET SESSION sql_mode = "STRICT_ALL_TABLES"'); db()->exec("INSERT INTO Booking (client_id,stylist_id,service_id,start_time,end_time,status,manage_token) VALUES ($dave,1,1,'2026-12-01 10:00:00','2026-12-01 11:00:00','paid','" . str_repeat('x', 64) . "')"); } catch (Throwable) { $q4ok = true; }
t('TC-I04', 'the database itself rejects price 0, an end before the start, a booking for a client who does not exist, and an invalid status (strict mode)', $q1ok && $q2ok && $q3ok && $q4ok, "price0:$q1ok end:$q2ok client:$q3ok status:$q4ok");
$q5ok = false; try { db()->exec("DELETE FROM Client WHERE client_id = $dave"); } catch (Throwable) { $q5ok = true; }
$sty = $adm->post('/admin_stylists.php', ['csrf_token' => $adm->csrf('/admin_stylists.php'), 'action' => 'delete', 'stylist_id' => 1]);
t('TC-I05', 'a client who has bookings cannot be deleted, and a stylist who has bookings cannot be deleted', $q5ok && (int) one('SELECT COUNT(*) FROM Stylist WHERE stylist_id = 1') === 1);
$cut = (new DateTimeImmutable('now'))->modify('+1 day')->format('Y-m-d H:i:s');
$overlaps = (int) one('SELECT COUNT(*) FROM Booking a JOIN Booking b ON a.stylist_id = b.stylist_id AND a.booking_id < b.booking_id AND a.status <> "cancelled" AND b.status <> "cancelled" AND a.start_time < b.end_time AND a.end_time > b.start_time WHERE a.start_time > ? AND b.start_time > ?', [$cut, $cut]);
t('TC-I01', 'no two active bookings of one stylist overlap anywhere (after all the booking, moving and race tests above)', $overlaps === 0, "$overlaps overlaps");
$passed = fn (string $tc) => in_array('PASS', array_column(array_filter($results, fn ($r) => $r[0] === $tc), 1), true);
t('TC-I03', 'a cancelled or moved booking frees its time at once (checked in TC-M01 and TC-M02)', $passed('TC-M01') && $passed('TC-M02'));

// ============================================================ X: security
heading('Security');
$hashes = q('SELECT password_hash FROM Administrator UNION ALL SELECT password_hash FROM Stylist'); $allHash = true; foreach ($hashes as $h) { if (!str_starts_with($h['password_hash'], '$2y$')) { $allHash = false; } }
t('TC-X01', 'every stored password is a bcrypt hash (starts with $2y$), never readable text', $allHash && count($hashes) >= 4);
$inj = "' OR '1'='1"; $drop = "x'; DROP TABLE Booking; --"; [$r, $tokI] = book($anon, 1, 1, freshSlot(), $inj, 'inj@example.com', ['notes' => $drop]); $bI = bookingIdOf($tokI);
$lg = (new Http($base)); $lgr = $lg->post('/login.php', ['email' => $inj, 'password' => $inj, 'csrf_token' => $lg->csrf('/login.php')]);
t('TC-X02', "SQL text typed in the login, the name and the notes is stored and shown as plain text; every table still exists", $bI !== null && one('SELECT c.name FROM Booking b JOIN Client c ON c.client_id = b.client_id WHERE b.booking_id = ?', [$bI]) === $inj && $lgr['status'] === 200 && (int) one('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') === 10, 'booking ' . var_export($bI, true) . ' name ' . (string) ($bI ? one('SELECT c.name FROM Booking b JOIN Client c ON c.client_id = b.client_id WHERE b.booking_id = ?', [$bI]) : '') . ' login ' . $lgr['status'] . ' tables ' . (int) one('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') . ' post ' . $r['status']);
[$r, $tokX] = book($anon, 1, 1, freshSlot(), '<script>alert(1)</script> Xss', 'xss@example.com'); $dX = substr((string) one('SELECT start_time FROM Booking WHERE manage_token = ?', [$tokX]), 0, 10); $bX = bookingIdOf($tokX);
$px = $thandi->get('/stylist_schedule.php?view=day&date=' . $dX)['body'] . $adm->get('/admin_dashboard.php?from=' . $dX . '&to=' . $dX)['body'] . $adm->get('/admin_reschedule.php?booking_id=' . $bX)['body'];
t('TC-X03', 'a <script> in a client name is shown as harmless text on the stylist, dashboard and reschedule pages', !str_contains($px, '<script>alert(1)') && str_contains($px, '&lt;script&gt;alert(1)'));
$pages = ['/admin_dashboard.php', '/admin_reschedule.php?booking_id=1', '/stylist_schedule.php', '/admin_services.php', '/admin_reports.php', '/admin_loyalty.php', '/admin_booking.php?booking_id=1', '/admin_admins.php', '/admin_stylists.php', '/admin_report_register.php', '/admin_report_takings.php', '/admin_report_stylists.php', '/admin_report_cancellations.php', '/admin_report_customer.php', '/admin_report_overview.php']; $allRedirect = true; foreach ($pages as $p) { $x = $anon->get($p); if ($x['status'] !== 302) { $allRedirect = false; } }
t('TC-X04', 'without logging in, every staff page sends the visitor to the login page', $allRedirect);
$stylistBlocked = true; foreach (['/admin_dashboard.php', '/admin_services.php', '/admin_stylists.php', '/admin_reports.php', '/admin_loyalty.php', '/admin_admins.php', '/admin_report_register.php', '/admin_report_takings.php', '/admin_report_stylists.php', '/admin_report_cancellations.php', '/admin_report_customer.php', '/admin_report_overview.php'] as $p) { if ($thandi->get($p)['status'] !== 403) { $stylistBlocked = false; } }
t('TC-X05', 'a stylist is refused (403) on every administrator page', $stylistBlocked);
$cs = $adm->post('/admin_dashboard.php', ['booking_id' => $bidRs]); $cs2 = $adm->post('/admin_loyalty.php', ['action' => 'adjust', 'client_id' => $aid, 'points' => '5', 'note' => 'no token']);
t('TC-X06', 'staff forms sent without the hidden security token do nothing', has($cs['body'] . $adm->get('/admin_dashboard.php')['body'], 'session expired') && (int) one('SELECT SUM(points) FROM LoyaltyLedger WHERE client_id = ?', [$aid]) === 70);
$x7a = $rohan->get('/inspiration_image.php?booking_id=' . $bidP); $x7b = $anon->get('/inspiration_image.php?booking_id=' . $bidP); $x7c = $anon->get('/storage/inspiration/' . (photoFiles()[0] ?? 'x.jpg')); $x7d = $anon->get('/inspiration_image.php?token=' . $tokP);
t('TC-X07', "another stylist gets 'not found', a visitor is refused, the storage folder is not reachable by address, and the client's own link still works", $x7a['status'] === 404 && $x7b['status'] === 403 && $x7c['status'] === 404 && $x7d['status'] === 200);
$tk = array_column(q('SELECT manage_token FROM Booking ORDER BY booking_id DESC LIMIT 20'), 'manage_token'); $tkOk = true; foreach ($tk as $x) { if (!preg_match('/^[a-f0-9]{64}$/', $x)) { $tkOk = ($x === str_repeat('a', 64) || strlen($x) === 64) ? $tkOk : false; } }
t('TC-X08', 'private links are 64 characters and every one is different', count(array_unique($tk)) === count($tk) && $tkOk);
$cookie = ''; foreach ($thandi->setCookieLines as $c) { if (stripos($c, 'session') !== false) { $cookie = $c; } }
t('TC-X09', 'the session cookie is HttpOnly and SameSite=Lax (the Secure flag only exists on https: check that on the live site by hand)', stripos($cookie, 'httponly') !== false && stripos($cookie, 'samesite=lax') !== false, $cookie);
$exp = ['/config/db_config.php', '/classes/Booking.php', '/tools/backup_db.php', '/backups/', '/database/schema.sql', '/storage/', '/MANIFEST.json']; $expOk = true; $exposed = []; $homeBody = $anon->get('/index.php')['body']; foreach ($exp as $p) { $x = $anon->get($p); if ($x['status'] === 200 && $x['body'] !== $homeBody) { $expOk = false; $exposed[] = $p; } }
t('TC-X10', 'settings, classes, tools and backups cannot be opened by address (the helper pages on the LIVE site are checked by hand)', $expOk, 'opened: ' . implode(' ', $exposed));
$open = true; foreach (['/admin_reports.php', '/admin_report_overview.php?from=2026-09-01&to=2026-09-30&export=csv', '/admin_report_register.php?from=2026-09-01&to=2026-09-30&export=csv', '/admin_report_takings.php?from=2026-09-01&to=2026-09-30&export=csv', '/admin_report_stylists.php?from=2026-09-01&to=2026-09-30&export=csv', '/admin_report_cancellations.php?from=2026-09-01&to=2026-09-30&export=csv', '/admin_report_customer.php?client_id=1&export=csv', '/admin_loyalty.php'] as $p) { $a = $anon->get($p); $b = $thandi->get($p); if ($a['status'] !== 302 || $b['status'] !== 403 || str_contains($a['body'] . $b['body'], 'SUMMARY')) { $open = false; } }
t('TC-X11', 'Reports, the CSV download and Loyalty give a visitor the login page and a stylist a no-access answer, and no data', $open);

// ============================================================ C / D: client accounts and admin access
heading('Client accounts (optional) and admin access');
$reg = fn (Http $h, string $nm, string $em) => $h->post('/register.php', ['csrf_token' => $h->csrf('/register.php'), 'name' => $nm, 'email' => $em]);
$linkOf = function (): ?string { $m = mailbox()['messages'][0] ?? null; return ($m && preg_match('/set_password\.php\?token=([a-f0-9]{64})/', mailBody($m), $x)) ? $x[1] : null; };
$setPw = fn (Http $h, string $tok, string $p, ?string $c = null) => $h->post('/set_password.php', ['csrf_token' => $h->csrf('/set_password.php?token=' . $tok), 'token' => $tok, 'password' => $p, 'confirm' => $c ?? $p]);
$SENT = 'we have sent it a link';
$amyHttp = null;
if ($mailpit) {
    @file_get_contents('http://127.0.0.1:8025/api/v1/messages', false, stream_context_create(['http' => ['method' => 'DELETE']]));
    $v1 = new Http($base); $r = $reg($v1, 'Carla Client', 'carla@example.com'); usleep(600000); $tokC = $linkOf(); $nBefore = (int) (mailbox()['total'] ?? 0); $reg($v1, 'Carla Client', 'carla@example.com'); usleep(400000);
    t('TC-C01', 'creating an account: the page gives the same message for anyone, a one-time link is emailed, no client record exists until the link is used, and asking again within a minute sends nothing more',
        has($r['body'], $SENT) && $tokC !== null && (int) one("SELECT COUNT(*) FROM Client WHERE email = 'carla@example.com'") === 0 && (int) (mailbox()['total'] ?? 0) === $nBefore && (int) one("SELECT COUNT(*) FROM AccountToken WHERE email = 'carla@example.com'") === 1 && (int) one('SELECT COUNT(*) FROM AccountToken WHERE token_hash = ?', [$tokC ?? '']) === 0);
    $carla = new Http($base); $r = $setPw($carla, (string) $tokC, 'ClientPass#2026'); $mine = $carla->get('/my_appointments.php');
    t('TC-C02', 'the link lets the client choose a password: the account is created (bcrypt hash), they are logged in, and My appointments shows 0 loyalty points', $r['status'] === 302 && ($r['headers']['location'] ?? '') === 'my_appointments.php' && str_starts_with((string) one("SELECT password_hash FROM Client WHERE email = 'carla@example.com'"), '$2y$') && $mine['status'] === 200 && str_contains($mine['body'], '<strong>0</strong> loyalty points'));
    $again = $setPw(new Http($base), (string) $tokC, 'AnotherPass#2026'); $fake = (new Http($base))->get('/set_password.php?token=' . str_repeat('f', 64));
    $reg(new Http($base), 'Dan Late', 'dan@example.com'); usleep(500000); $tokD = $linkOf(); run("UPDATE AccountToken SET expires_at = '2000-01-01 00:00:00' WHERE email = 'dan@example.com'"); $exp = (new Http($base))->get('/set_password.php?token=' . $tokD);
    $reg(new Http($base), 'Eve Short', 'eve2@example.com'); usleep(500000); $tokE = $linkOf(); $shortR = $setPw(new Http($base), (string) $tokE, '123456789'); $mismR = $setPw(new Http($base), (string) $tokE, 'abcdefghij', 'abcdefghiX');
    t('TC-C03', 'the link works once, a made-up or expired link is refused, and a 9-character password or two different passwords are refused without using up the link', has($again['body'], 'expired or has already been used') && has($fake['body'], 'expired or has already been used') && has($exp['body'], 'expired or has already been used') && has($shortR['body'], 'at least 10') && has($mismR['body'], 'not the same') && (int) one("SELECT COUNT(*) FROM Client WHERE email = 'eve2@example.com'") === 0 && str_contains((new Http($base))->get('/set_password.php?token=' . $tokE)['body'], 'name="password"'));
    $reg(new Http($base), 'Amy Account', 'amy@example.com'); usleep(500000); $tokA = $linkOf(); $amyHttp = new Http($base); $setPw($amyHttp, (string) $tokA, 'AmyPass#2026'); $amyPage = $amyHttp->get('/my_appointments.php')['body'];
    t('TC-C04', 'a client who booked as a guest and then makes an account with the same email sees those bookings (by reference) and the loyalty points they earned', str_contains($amyPage, bookingReference((int) $pastId)) && str_contains($amyPage, '<strong>15</strong> loyalty points') && str_contains($amyPage, 'Past and cancelled'));
    $badPages = ['/admin_dashboard.php', '/stylist_schedule.php', '/admin_admins.php', '/admin_report_register.php', '/admin_stylists.php']; $allBlocked = true; foreach ($badPages as $bp) { if ($carla->get($bp)['status'] !== 403) { $allBlocked = false; } }
    t('TC-C05', 'a client account cannot open any staff or administrator page (403), and a visitor is sent to log in from My appointments', $allBlocked && $anon->get('/my_appointments.php')['status'] === 302);
    $form = $amyHttp->get('/book_details.php?service_id=1&stylist_id=1&start=' . urlencode(freshSlot()))['body']; $slotC6 = freshSlot();
    $rb = $amyHttp->post('/book_details.php', ['service_id' => 1, 'stylist_id' => 1, 'start' => $slotC6, 'name' => 'Amy Account', 'email' => 'someone-else@example.com', 'phone' => '0821234567', 'notes' => '', 'allergies' => '']); $tokB6 = tokenFrom($rb);
    t('TC-C06', 'booking while signed in: the form is filled in with the email locked, and the booking is saved under the ACCOUNT email whatever the form says, and shows in My appointments', str_contains($form, 'readonly') && $tokB6 !== null && one('SELECT c.email FROM Booking b JOIN Client c ON c.client_id = b.client_id WHERE b.manage_token = ?', [$tokB6]) === 'amy@example.com' && str_contains($amyHttp->get('/my_appointments.php')['body'], (string) $tokB6));
    run("UPDATE AccountToken SET created_at = '2000-01-01 00:00:00' WHERE email = 'amy@example.com'"); $reg(new Http($base), 'Amy Account', 'amy@example.com'); usleep(500000); $subjR = mailbox()['messages'][0]['Subject'] ?? ''; $tokR = $linkOf(); $h2 = new Http($base); $setPw($h2, (string) $tokR, 'AmyNewPass#2027');
    t('TC-C07', 'a forgotten password: asking again for an existing account emails a RESET link; afterwards the old password no longer works and the new one does', stripos($subjR, 'reset') !== false && (new Http($base))->login('amy@example.com', 'AmyPass#2026') === 200 && (new Http($base))->login('amy@example.com', 'AmyNewPass#2027') === 302);
} else {
    foreach (['TC-C01' => 'create an account', 'TC-C02' => 'choose a password', 'TC-C03' => 'link rules', 'TC-C04' => 'guest history', 'TC-C05' => 'client cannot open staff pages', 'TC-C06' => 'booking while signed in', 'TC-C07' => 'forgotten password'] as $tc => $w) { skip($tc, $w, 'start Mailpit and run again'); }
}
$myAdminId = (int) one("SELECT admin_id FROM Administrator WHERE email = 'test-admin@example.com'");
$grant = fn (Http $h, string $nm, string $em) => $h->post('/admin_admins.php', ['csrf_token' => $h->csrf('/admin_admins.php'), 'action' => 'add', 'name' => $nm, 'email' => $em]);
run('INSERT INTO Client (name, email, password_hash) VALUES (?, ?, ?)', ['Dee Client', 'dee@example.com', password_hash('DeePass#2026', PASSWORD_DEFAULT)]); $grant($adm, 'Dee Client', 'dee@example.com'); $dee = new Http($base); $deeLogin = $dee->login('dee@example.com', 'DeePass#2026');
t('TC-D01', 'giving admin access to an existing client account: they keep their own password, sign in as an administrator (dashboard opens) and are still a client (My appointments opens)', (int) one("SELECT COUNT(*) FROM Administrator WHERE email = 'dee@example.com' AND client_id IS NOT NULL AND password_hash IS NULL") === 1 && $deeLogin === 302 && $dee->get('/admin_dashboard.php')['status'] === 200 && $dee->get('/my_appointments.php')['status'] === 200);
$grant($adm, 'New Nadia', 'nadia@example.com'); $adminsPage = $adm->get('/admin_admins.php')['body']; $nadiaTok = preg_match('/set_password\.php\?token=([a-f0-9]{64})/', $adminsPage, $mt) ? $mt[1] : null; $again2 = $adm->get('/admin_admins.php')['body'];
$nadia = new Http($base); $beforeSet = (new Http($base))->login('nadia@example.com', 'NadiaPass#2026'); if ($nadiaTok) { $setPw($nadia, $nadiaTok, 'NadiaPass#2026'); }
t('TC-D02', 'giving admin access to a new person: an account is created without a password and shown as "waiting", a one-time link is shown ONCE, they cannot log in until they use it, and then they are an administrator', $nadiaTok !== null && has($adminsPage, 'Waiting for them to choose a password') && !str_contains($again2, '/set_password.php?token=') && $beforeSet === 200 && $nadia->get('/admin_dashboard.php')['status'] === 200);
$nadiaAdminId = (int) one("SELECT admin_id FROM Administrator WHERE email = 'nadia@example.com'"); $before = $nadia->get('/admin_dashboard.php')['status'];
$adm->post('/admin_admins.php', ['csrf_token' => $adm->csrf('/admin_admins.php'), 'action' => 'remove', 'admin_id' => $nadiaAdminId]); $after = $nadia->get('/admin_dashboard.php'); $asClient = new Http($base); $asClient->login('nadia@example.com', 'NadiaPass#2026');
t('TC-D03', 'removing admin access takes effect IMMEDIATELY for someone already logged in; their client account stays and they are then only a client', $before === 200 && $after['status'] === 302 && str_contains($after['headers']['location'] ?? '', 'login') && (int) one("SELECT COUNT(*) FROM Client WHERE email = 'nadia@example.com'") === 1 && $asClient->get('/my_appointments.php')['status'] === 200 && $asClient->get('/admin_dashboard.php')['status'] === 403);
$adm->post('/admin_admins.php', ['csrf_token' => $adm->csrf('/admin_admins.php'), 'action' => 'remove', 'admin_id' => $myAdminId]); $evil = $thandi->post('/admin_admins.php', ['csrf_token' => $thandi->csrf('/stylist_schedule.php'), 'action' => 'add', 'name' => 'Evil', 'email' => 'evil@example.com']);
t('TC-D04', 'you cannot remove yourself, a stylist is refused (403) and nothing is added by a hand-made form, and a visitor is sent to log in', (int) one('SELECT COUNT(*) FROM Administrator WHERE admin_id = ?', [$myAdminId]) === 1 && $evil['status'] === 403 && (int) one("SELECT COUNT(*) FROM Administrator WHERE email = 'evil@example.com'") === 0 && $anon->get('/admin_admins.php')['status'] === 302);

// ============================================================ W: stylist services, working blocks and "taking bookings"
heading('Stylist services, working-hour blocks and "taking bookings"');
$stylAct = fn (string $action, array $f) => $adm->post('/admin_stylists.php', array_merge(['csrf_token' => $adm->csrf('/admin_stylists.php'), 'action' => $action], $f));
$farMon = nextWeekday(1, 45)->format('Y-m-d'); $farTue = nextWeekday(2, 45)->format('Y-m-d');
$stylAct('save_services', ['stylist_id' => 2, 'services' => ['1', '2']]); $hc = $anon->get('/book.php?service_id=3')['body']; $nW = (int) one('SELECT COUNT(*) FROM Booking');
[$rW, $tW] = book($anon, 3, 2, freshSlot(), 'Edited Ed', 'ed@example.com'); [$rW2, $tW2] = book($anon, 1, 2, freshSlot(), 'Allowed Al', 'al2@example.com'); $stylAct('save_services', ['stylist_id' => 2]); $kept = (int) one('SELECT COUNT(*) FROM StylistService WHERE stylist_id = 2');
t('TC-W01', 'a stylist is offered only for the services ticked for them: Hair Colour no longer lists Rohan, a hand-edited booking for it is refused, a ticked service still works, and saving with nothing ticked is refused', !has($hc, 'Rohan') && has($hc, 'Thandi') && $rW['status'] === 409 && (int) one('SELECT COUNT(*) FROM Booking') === $nW + 1 && $rW2['status'] === 302 && $kept === 2);
$stylAct('save_services', ['stylist_id' => 2, 'services' => ['1', '2', '3', '4', '5']]);
run('DELETE FROM WorkingBlock WHERE stylist_id = 1 AND weekday = 1'); $stylAct('add_block', ['stylist_id' => 1, 'day' => 1, 'from' => '09:00', 'to' => '13:00']); $stylAct('add_block', ['stylist_id' => 1, 'day' => 1, 'from' => '14:00', 'to' => '17:00']);
$s30 = slotTimes($anon->get("/book.php?service_id=1&stylist_id=1&date=$farMon")['body']); $s45 = slotTimes($anon->get("/book.php?service_id=4&stylist_id=1&date=$farMon")['body']);
[$rX1] = book($anon, 4, 1, "$farMon 12:30", 'Cross Chris', 'chris@example.com'); [$rX2] = book($anon, 1, 1, "$farMon 13:00", 'Lunch Lee', 'lee@example.com'); [$rX3] = book($anon, 1, 1, "$farMon 12:30", 'Before Ben', 'ben2@example.com'); [$rX4] = book($anon, 1, 1, "$farMon 14:00", 'After Amy', 'amy3@example.com');
$stylAct('add_block', ['stylist_id' => 1, 'day' => 1, 'from' => '12:00', 'to' => '15:00']); $nBlocks = (int) one('SELECT COUNT(*) FROM WorkingBlock WHERE stylist_id = 1 AND weekday = 1');
t('TC-W02', 'a lunch break (two blocks): nothing is offered from 13:00 to 13:45, a 45-minute service must finish before the break (12:00 is the last morning start), a booking running into the break is refused, one ending exactly at 13:00 or starting at 14:00 works, and an overlapping block is refused',
    in_array('12:30', $s30, true) && !in_array('13:00', $s30, true) && !in_array('13:30', $s30, true) && in_array('14:00', $s30, true) && in_array('12:00', $s45, true) && !in_array('12:30', $s45, true) && !in_array('13:00', $s45, true) && $rX1['status'] === 409 && $rX2['status'] === 409 && $rX3['status'] === 302 && $rX4['status'] === 302 && $nBlocks === 2);
run('DELETE FROM WorkingBlock WHERE stylist_id = 1 AND weekday = 1'); run("INSERT INTO WorkingBlock (stylist_id, weekday, start_time, end_time) VALUES (1, 1, '09:00:00', '17:00:00')");
[$rS, $tokS] = book($anon, 1, 3, "$farTue 10:00", 'Existing Eve', 'eve3@example.com'); $stylAct('toggle', ['stylist_id' => 3, 'accepting' => '0']);
$listS = $anon->get('/book.php?service_id=1')['body']; $homeS = $anon->get('/index.php')['body']; $directS = $anon->get("/book.php?service_id=1&stylist_id=3&date=$farTue"); [$rS2] = book($anon, 1, 3, "$farTue 11:00", 'Too Late Tim', 'tim2@example.com'); $mvS = $anon->post('/reschedule.php', ['token' => $tokS, 'new_start' => "$farTue 12:00"]); $stillOk = one('SELECT status FROM Booking WHERE manage_token = ?', [$tokS]);
$anon->post('/cancel_booking.php', ['token' => $tokS]); $cancelledOk = one('SELECT status FROM Booking WHERE manage_token = ?', [$tokS]); $stylAct('toggle', ['stylist_id' => 3, 'accepting' => '1']);
t('TC-W03', '"Stop taking bookings": the stylist disappears from the booking page and the home page, a hand-made booking is refused, a booking made before stays confirmed but cannot be moved (and says why), the client can still cancel it, and "Start taking bookings" brings them back',
    !has($listS, 'Sipho') && !has($homeS, 'Sipho') && count(slotTimes($directS['body'])) === 0 && $rS2['status'] === 409 && $stillOk === 'confirmed' && has($mvS['body'], 'not taking bookings') && $cancelledOk === 'cancelled' && has($anon->get('/book.php?service_id=1')['body'], 'Sipho'));
$nSty = (int) one('SELECT COUNT(*) FROM Stylist'); $stylAct('add', ['name' => 'No Services', 'email' => 'noserv@x.test', 'password' => 'NewStylistPass#1', 'first_day' => 'Mon', 'last_day' => 'Fri', 'open_time' => '09:00', 'close_time' => '17:00']); $refusedNone = (int) one('SELECT COUNT(*) FROM Stylist') === $nSty;
$stylAct('add', ['name' => 'Nia New', 'email' => 'nia@x.test', 'password' => 'NewStylistPass#1', 'first_day' => 'Mon', 'last_day' => 'Fri', 'open_time' => '09:00', 'close_time' => '17:00', 'services' => ['2', '4']]); $niaId = (int) one("SELECT stylist_id FROM Stylist WHERE email = 'nia@x.test'");
$stylAct('add', ['name' => 'Dup', 'email' => 'rohan@executivegrooming.test', 'password' => 'NewStylistPass#1', 'first_day' => 'Mon', 'last_day' => 'Fri', 'open_time' => '09:00', 'close_time' => '17:00', 'services' => ['1']]); $dupRefused = (int) one('SELECT COUNT(*) FROM Stylist WHERE email = "rohan@executivegrooming.test"') === 1;
$stylAct('delete', ['stylist_id' => 1]); $stylAct('delete', ['stylist_id' => $niaId]);
t('TC-W04', 'adding a stylist needs at least one service ticked, a duplicate email is refused, a new stylist gets exactly the ticked services and the starting hours and can log in, a stylist with bookings cannot be deleted, and one without is deleted with their services and blocks',
    $refusedNone && $niaId > 0 && $dupRefused && (int) one('SELECT COUNT(*) FROM Stylist WHERE stylist_id = 1') === 1 && (int) one('SELECT COUNT(*) FROM Stylist WHERE stylist_id = ?', [$niaId]) === 0 && (int) one('SELECT COUNT(*) FROM StylistService WHERE stylist_id = ?', [$niaId]) === 0 && (int) one('SELECT COUNT(*) FROM WorkingBlock WHERE stylist_id = ?', [$niaId]) === 0);
[$rWn, $tokWn] = book($anon, 1, 2, "$farTue 10:00", 'Warn Wendy', 'wendy@example.com'); $tueBlock = (int) one('SELECT block_id FROM WorkingBlock WHERE stylist_id = 2 AND weekday = 2'); $stylAct('remove_block', ['stylist_id' => 2, 'block_id' => $tueBlock]);
$warnPage = $adm->get('/admin_stylists.php')['body']; $warnAgain = $adm->get('/admin_stylists.php')['body']; run("INSERT INTO WorkingBlock (stylist_id, weekday, start_time, end_time) VALUES (2, 2, '09:00:00', '17:00:00')");
t('TC-W05', 'removing working hours that an upcoming booking relies on shows a warning once, and leaves the booking untouched', $rWn['status'] === 302 && has($warnPage, 'no longer fit') && !has($warnAgain, 'no longer fit') && one('SELECT status FROM Booking WHERE manage_token = ?', [$tokWn]) === 'confirmed');

// ============================================================ S / A: cancelling and marking appointments from the stylist and administrator portals
heading('Cancelling and marking appointments (stylist and administrator portals)');
run('INSERT INTO Client (name, email) VALUES (?, ?)', ['Mark Mia', 'mia@example.com']); $miaId = (int) db()->lastInsertId();
$startedBooking = function (int $stylist, int $minutesAgo, string $token) use ($miaId): int {
    $st = (new DateTimeImmutable('now'))->modify("-$minutesAgo minutes");
    run("INSERT INTO Booking (client_id, stylist_id, service_id, start_time, end_time, status, price_charged, manage_token) VALUES (?, ?, 1, ?, ?, 'confirmed', 150, ?)", [$miaId, $stylist, $st->format('Y-m-d H:i:s'), $st->modify('+30 minutes')->format('Y-m-d H:i:s'), $token]);
    return (int) one('SELECT booking_id FROM Booking WHERE manage_token = ?', [$token]);
};
$stylistPost = fn (Http $h, array $f) => $h->post('/stylist_schedule.php', array_merge(['csrf_token' => $h->csrf('/stylist_schedule.php')], $f));
$adminPost   = fn (array $f) => $adm->post('/admin_dashboard.php', array_merge(['csrf_token' => $adm->csrf('/admin_dashboard.php')], $f));
$flashOf     = fn (Http $h, string $path) => preg_match('/class="(?:success|error)">([^<]*)/', $h->get($path)['body'], $fm) ? decoded($fm[1]) : '';
$statusOf    = fn (int $id) => (string) one('SELECT status FROM Booking WHERE booking_id = ?', [$id]);

$slotS = freshSlot(); [$rS, $tokS11] = book($anon, 1, 1, $slotS, 'Cancel Cora', 'cora@example.com'); $idS = (int) bookingIdOf($tokS11); $dayS = substr($slotS, 0, 10);
$pageBefore = $thandi->get('/stylist_schedule.php?view=day&date=' . $dayS)['body'];
if ($mailpit) { @file_get_contents('http://127.0.0.1:8025/api/v1/messages', false, stream_context_create(['http' => ['method' => 'DELETE']])); }
$stylistPost($thandi, ['action' => 'cancel', 'booking_id' => $idS, 'view' => 'day', 'date' => $dayS]); usleep(500000);
$msgS = $flashOf($thandi, '/stylist_schedule.php?view=day&date=' . $dayS);
$freed = in_array(substr($slotS, 11), slotTimes($anon->get("/book.php?service_id=1&stylist_id=1&date=$dayS")['body']), true);
$emailedS = !$mailpit || stripos(mailbox()['messages'][0]['Subject'] ?? '', 'cancel') !== false;
t('TC-S11', 'a stylist can CANCEL one of their own upcoming appointments: a Cancel button is offered, the status becomes cancelled, the time is free again, the client is emailed, and the history says "Stylist: Thandi Mokoena"',
    str_contains($pageBefore, 'name="action" value="cancel"') && $statusOf($idS) === 'cancelled' && has($msgS, 'cancelled') && $freed && $emailedS && one("SELECT actor FROM BookingLog WHERE booking_id = ? AND action = 'cancelled'", [$idS]) === 'Stylist: Thandi Mokoena');
[$rR, $tokR12] = book($anon, 1, 2, freshSlot(), 'Rohan Client', 'rc@example.com'); $idRohan = (int) bookingIdOf($tokR12);
$idStarted = $startedBooking(1, 90, hash('sha256', 's12-started'));
$stylistPost($thandi, ['action' => 'cancel', 'booking_id' => $idRohan]); $notMine = $statusOf($idRohan) === 'confirmed';
$stylistPost($thandi, ['action' => 'cancel', 'booking_id' => $idStarted]); $startedMsg = $flashOf($thandi, '/stylist_schedule.php');
$logsBefore = (int) one("SELECT COUNT(*) FROM BookingLog WHERE booking_id = ? AND action = 'cancelled'", [$idS]); $stylistPost($thandi, ['action' => 'cancel', 'booking_id' => $idS]); $twiceMsg = $flashOf($thandi, '/stylist_schedule.php');
$thandi->post('/stylist_schedule.php', ['action' => 'cancel', 'booking_id' => $idRohan]); $anonTry = $anon->post('/stylist_schedule.php', ['action' => 'cancel', 'booking_id' => $idRohan]);
$todayPage = $thandi->get('/stylist_schedule.php?view=day&date=' . (new DateTimeImmutable('now'))->modify('-90 minutes')->format('Y-m-d'))['body'];
t('TC-S12', 'a stylist can NOT cancel another stylist\'s appointment, one that has already started, or one that is already cancelled, nothing happens without the security token, a visitor is sent to log in, and a started appointment shows no Cancel button',
    $notMine && $statusOf($idStarted) === 'confirmed' && has($startedMsg, 'Only upcoming') && has($twiceMsg, 'already been cancelled') && (int) one("SELECT COUNT(*) FROM BookingLog WHERE booking_id = ? AND action = 'cancelled'", [$idS]) === $logsBefore
    && $statusOf($idRohan) === 'confirmed' && $anonTry['status'] === 302 && !preg_match('/name="booking_id" value="' . $idStarted . '">\s*<input type="hidden" name="action" value="cancel"/', $todayPage));

$idA = $startedBooking(2, 120, hash('sha256', 'a18-a')); $idB = $startedBooking(1, 100, hash('sha256', 'a18-b')); [$rF, $tokF] = book($anon, 1, 1, freshSlot(), 'Future Fay', 'fay2@example.com'); $idF = (int) bookingIdOf($tokF);
$dayN = (new DateTimeImmutable('now'))->modify('-120 minutes')->format('Y-m-d'); $dashStarted = $adm->get("/admin_dashboard.php?from=$dayN&to=$dayN")['body']; $dayF = (string) substr((string) one('SELECT start_time FROM Booking WHERE booking_id = ?', [$idF]), 0, 10); $dashFuture = $adm->get("/admin_dashboard.php?from=$dayF&to=$dayF")['body'];
$hasOutcomeBtn = fn (string $body, int $id, string $o) => (bool) preg_match('/name="booking_id" value="' . $id . '">\s*<input type="hidden" name="action" value="outcome">\s*<input type="hidden" name="outcome" value="' . $o . '">/', $body);
$adminPost(['action' => 'outcome', 'outcome' => 'completed', 'booking_id' => $idA]); $okMsg = $flashOf($adm, '/admin_dashboard.php'); $ptsA = (int) one('SELECT COALESCE(SUM(points), 0) FROM LoyaltyLedger WHERE booking_id = ?', [$idA]);
$adminPost(['action' => 'outcome', 'outcome' => 'no-show', 'booking_id' => $idB]); $adminPost(['action' => 'outcome', 'outcome' => 'no-show', 'booking_id' => $idF]); $earlyMsg = $flashOf($adm, '/admin_dashboard.php'); $earlyRefused = $statusOf($idF) === 'confirmed' && has($earlyMsg, 'after the appointment has started'); $adminPost(['action' => 'outcome', 'outcome' => 'completed', 'booking_id' => $idF]); $earlyDone = $statusOf($idF) === 'completed' && str_contains((string) one("SELECT detail FROM BookingLog WHERE booking_id = ? AND action = 'completed'", [$idF]), 'before the appointment started');
$adminPost(['action' => 'outcome', 'outcome' => 'no-show', 'booking_id' => $idA]); $twiceMsgA = $flashOf($adm, '/admin_dashboard.php');
t('TC-A18', 'an administrator can mark ANY stylist\'s appointment Completed (the client earns 15 points; before it starts it is allowed and noted in the history) or No-show (only once it has started), but not one that is already marked; the No-show button appears only on started appointments, and the history says "Administrator: Test Admin"',
    $hasOutcomeBtn($dashStarted, $idA, 'completed') && $hasOutcomeBtn($dashStarted, $idA, 'no-show') && $hasOutcomeBtn($dashFuture, $idF, 'completed') && !$hasOutcomeBtn($dashFuture, $idF, 'no-show') && $statusOf($idA) === 'completed' && has($okMsg, 'earned 15 loyalty points') && $ptsA === 15
    && $statusOf($idB) === 'no-show' && $earlyRefused && $earlyDone && has($twiceMsgA, 'already been marked') && $statusOf($idA) === 'completed' && (int) one('SELECT COALESCE(SUM(points), 0) FROM LoyaltyLedger WHERE booking_id = ?', [$idA]) === 15
    && one("SELECT actor FROM BookingLog WHERE booking_id = ? AND action = 'completed'", [$idA]) === 'Administrator: Test Admin' && one("SELECT actor FROM BookingLog WHERE booking_id = ? AND action = 'no-show'", [$idB]) === 'Administrator: Test Admin');
$idM = $startedBooking(1, 80, hash('sha256', 'a19-m'));
$stylistTry = $thandi->post('/admin_dashboard.php', ['csrf_token' => $thandi->csrf('/stylist_schedule.php'), 'action' => 'outcome', 'outcome' => 'completed', 'booking_id' => $idM]); $visitorTry = $anon->post('/admin_dashboard.php', ['action' => 'outcome', 'outcome' => 'completed', 'booking_id' => $idM]);
$noToken = $adm->post('/admin_dashboard.php', ['action' => 'outcome', 'outcome' => 'completed', 'booking_id' => $idM]); $adminPost(['action' => 'outcome', 'outcome' => 'paid', 'booking_id' => $idM]); $adminPost(['action' => 'outcome', 'outcome' => 'completed', 'booking_id' => 'abc']); $adminPost(['action' => 'steal', 'outcome' => 'completed', 'booking_id' => $idM]);
$stillConfirmed = $statusOf($idM) === 'confirmed'; [$rF2, $tokF2] = book($anon, 1, 1, freshSlot(), 'Safe Sam', 'sam3@example.com'); $idF2 = (int) bookingIdOf($tokF2); $adminPost(['action' => 'steal', 'booking_id' => $idF2]); $futureSafe = $statusOf($idF2) === 'confirmed'; $noPointsYet = (int) one('SELECT COUNT(*) FROM LoyaltyLedger WHERE booking_id = ?', [$idM]) === 0; $adminPost(['action' => 'outcome', 'outcome' => 'completed', 'booking_id' => $idM]);
t('TC-A19', 'the administrator buttons cannot be used by a stylist (403) or a visitor (login page), nothing happens without the security token, with an unknown outcome, a non-numeric id or an unknown action, and the same appointment works once those are removed',
    $stylistTry['status'] === 403 && $visitorTry['status'] === 302 && $stillConfirmed && $noPointsYet && $statusOf($idM) === 'completed' && $futureSafe,
    "stylist {$stylistTry['status']} visitor {$visitorTry['status']} stillConfirmed " . var_export($stillConfirmed, true) . ' future safe ' . var_export($futureSafe, true) . ' final ' . $statusOf($idM) . ' message: ' . $flashOf($adm, '/admin_dashboard.php'));

// ============================================================ S: a stylist's own History, Reschedule and early Completed
heading('A stylist\'s four actions on their own bookings: History, Reschedule, Cancel, Completed');
$slotR = freshSlot(); $dayR = substr($slotR, 0, 10); [$rR1, $tokR1] = book($anon, 1, 1, $slotR, 'Move Marta', 'marta@example.com'); $idR1 = (int) bookingIdOf($tokR1);
$schedBefore = $thandi->get('/stylist_schedule.php?view=day&date=' . $dayR)['body'];
$pickPage = $thandi->get('/admin_reschedule.php?booking_id=' . $idR1); $newSlotR = freshSlot(); $newDayR = substr($newSlotR, 0, 10);
if ($mailpit) { @file_get_contents('http://127.0.0.1:8025/api/v1/messages', false, stream_context_create(['http' => ['method' => 'DELETE']])); }
$csrfR = preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $pickPage['body'], $cm1) ? $cm1[1] : '';
$moveR = $thandi->post('/admin_reschedule.php', ['csrf_token' => $csrfR, 'booking_id' => $idR1, 'new_start' => $newSlotR]); usleep(500000);
$emailedR = !$mailpit || stripos(mailbox()['messages'][0]['Subject'] ?? '', 'reschedul') !== false;
$oldFree = in_array(substr($slotR, 11), slotTimes($anon->get("/book.php?service_id=1&stylist_id=1&date=$dayR")['body']), true);
t('TC-S13', 'a stylist can RESCHEDULE their own upcoming appointment: the schedule offers a Reschedule button, they pick a free time, the booking moves, the client is emailed, the old time is free again, they land back on their schedule, and the history says "Stylist: Thandi Mokoena"',
    str_contains($schedBefore, 'admin_reschedule.php?booking_id=' . $idR1) && $pickPage['status'] === 200 && $moveR['status'] === 302 && str_contains($moveR['headers']['location'] ?? '', 'stylist_schedule.php') && substr((string) one('SELECT start_time FROM Booking WHERE booking_id = ?', [$idR1]), 0, 16) === $newSlotR && $emailedR && $oldFree
    && one("SELECT actor FROM BookingLog WHERE booking_id = ? AND action = 'rescheduled'", [$idR1]) === 'Stylist: Thandi Mokoena');
[$rR2, $tokR2] = book($anon, 1, 2, freshSlot(), 'Rohan Roy', 'roy@example.com'); $idR2 = (int) bookingIdOf($tokR2); $startBefore = (string) one('SELECT start_time FROM Booking WHERE booking_id = ?', [$idR2]);
$otherGet = $thandi->get('/admin_reschedule.php?booking_id=' . $idR2); $otherPost = $thandi->post('/admin_reschedule.php', ['csrf_token' => $csrfR, 'booking_id' => $idR2, 'new_start' => freshSlot()]);
$otherHist = $thandi->get('/admin_booking.php?booking_id=' . $idR2); $startedR = $startedBooking(1, 70, hash('sha256', 's13-started')); $startedPage = $thandi->get('/admin_reschedule.php?booking_id=' . $startedR);
$histOwn = $thandi->get('/admin_booking.php?booking_id=' . $idR1); $histAdmin = $adm->get('/admin_booking.php?booking_id=' . $idR1);
t('TC-S14', 'a stylist can open the HISTORY of their own appointment (who booked and moved it, without the client\'s email), but another stylist\'s appointment is "not found" on both History and Reschedule and cannot be moved, an appointment that has started cannot be moved, and a visitor is sent to log in',
    $histOwn['status'] === 200 && has($histOwn['body'], 'Rescheduled') && has($histOwn['body'], 'Stylist: Thandi Mokoena') && !str_contains($histOwn['body'], 'marta@example.com') && str_contains($histAdmin['body'], 'marta@example.com')
    && $otherGet['status'] === 404 && $otherHist['status'] === 404 && $otherPost['status'] === 404 && (string) one('SELECT start_time FROM Booking WHERE booking_id = ?', [$idR2]) === $startBefore && has($startedPage['body'], 'already started')
    && $anon->get('/admin_booking.php?booking_id=' . $idR1)['status'] === 302 && $anon->get('/admin_reschedule.php?booking_id=' . $idR1)['status'] === 302);
$slotC = freshSlot(); $dayC = substr($slotC, 0, 10); [$rC, $tokC15] = book($anon, 1, 1, $slotC, 'Early Edna', 'edna@example.com'); $idC15 = (int) bookingIdOf($tokC15); $pageC = $thandi->get('/stylist_schedule.php?view=day&date=' . $dayC)['body'];
$noShowBtn = (bool) preg_match('/name="booking_id" value="' . $idC15 . '">\s*<input type="hidden" name="outcome" value="no-show">/', $pageC); $compBtn = (bool) preg_match('/onsubmit="return confirm\(\'This appointment has not started yet[^"]*"\s*>\s*<input type="hidden" name="csrf_token" value="[a-f0-9]+">\s*<input type="hidden" name="booking_id" value="' . $idC15 . '">\s*<input type="hidden" name="outcome" value="completed">/', $pageC);
$stylistPost($thandi, ['booking_id' => $idC15, 'outcome' => 'no-show', 'view' => 'day', 'date' => $dayC]); $earlyNo = $statusOf($idC15) === 'confirmed' && has($flashOf($thandi, '/stylist_schedule.php'), 'after the appointment has started');
$stylistPost($thandi, ['booking_id' => $idC15, 'outcome' => 'completed', 'view' => 'day', 'date' => $dayC]); $okMsgC = $flashOf($thandi, '/stylist_schedule.php');
t('TC-S15', 'a stylist can mark their own appointment COMPLETED before it starts (after a "not started yet, are you sure?" prompt): the client earns the points and the history notes it was marked early; a No-show button is not offered and a No-show is refused until it has started',
    $compBtn && !$noShowBtn && $earlyNo && $statusOf($idC15) === 'completed' && has($okMsgC, 'earned 15 loyalty points') && (int) one('SELECT COALESCE(SUM(points), 0) FROM LoyaltyLedger WHERE booking_id = ?', [$idC15]) === 15 && str_contains((string) one("SELECT detail FROM BookingLog WHERE booking_id = ? AND action = 'completed'", [$idC15]), 'before the appointment started'));

// ============================================================ O / N
heading('Operations and performance');
$out = phpRun([$root . '/tools/backup_db.php', '--dir=' . $backups]); $files = glob($backups . DIRECTORY_SEPARATOR . 'grooming-studio-*.sql.gz');
t('TC-O01', 'the backup tool saves a compressed backup and reports the rows in each table', str_contains($out, 'Backup saved') && count($files) === 1 && stripos($out, 'booking:') !== false, trim(substr($out, 0, 80)));
$restoreOk = false;
if (count($files) === 1) {
    $admin->exec("CREATE DATABASE `$restoreDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $rp = new PDO("mysql:host={$cfg['host']};dbname=$restoreDb;charset=utf8mb4", $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $rp->exec((string) gzdecode((string) file_get_contents($files[0]))); $restoreOk = true;
    foreach (['AccountToken', 'Administrator', 'Booking', 'BookingLog', 'Client', 'LoyaltyLedger', 'Service', 'Stylist', 'StylistService', 'WorkingBlock'] as $tb) { if ((int) $rp->query("SELECT COUNT(*) FROM `$tb`")->fetchColumn() !== (int) one("SELECT COUNT(*) FROM `$tb`")) { $restoreOk = false; } }
}
t('TC-O02', 'the backup restores into an empty database and every table has exactly the same number of rows', $restoreOk);
$dry = phpRun([$root . '/tools/cleanup_images.php', '--dry-run']);
t('TC-O03', 'the photo clean-up tool runs in report-only mode (the real deletion is tested by hand with an old appointment)', str_contains($dry, 'DRY RUN'));
if (is_file($root . '/MANIFEST.json')) { $v = phpRun([$root . '/tools/verify_files.php']); t('TC-O05', 'verify_files.php confirms every file is the official, latest version', str_contains($v, 'every file is the official')); }
else { skip('TC-O05', 'verify_files.php', 'MANIFEST.json is not in this project'); }
$tr = $anon->get('/index.php'); $js = $anon->get('/assets/js/theme.js');
t('TC-N02', 'the light/dark switch script is served and every public page loads it', $js['status'] === 200 && str_contains($tr['body'], 'assets/js/theme.js') && str_contains($anon->get('/book.php?service_id=1')['body'], 'assets/js/theme.js') && str_contains($anon->get('/login.php')['body'], 'assets/js/theme.js'));
$times = []; for ($i = 0; $i < 3; $i++) { $times[] = $anon->get('/book.php?service_id=1&stylist_id=1&date=' . $wedDay)['time']; } $avg = array_sum($times) / 3;
t('TC-N03', 'the booking page with free times answers in under 2 seconds (3 runs: ' . implode(', ', array_map(fn ($x) => number_format($x, 3), $times)) . ' s; average ' . number_format($avg, 3) . ' s; this is the LOCAL figure for Table 5.28)', max($times) < 2.0);
t('TC-N05', 'a booking is visible to the stylist and to the administrator immediately after it is made', has($thandi->get('/stylist_schedule.php?view=day&date=' . $phDay)['body'], 'Photo Pam') && has($adm->get('/admin_dashboard.php?from=' . $phDay . '&to=' . $phDay)['body'], 'Photo Pam'));
$log = is_file($logFile) ? (string) file_get_contents($logFile) : ''; $phpErr = preg_match_all('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)/i', $log);
t('TC-N00', 'the web server wrote no PHP warnings, notices or fatal errors during all these tests', $phpErr === 0, $phpErr ? (string) preg_replace('/\s+/', ' ', substr($log, 0, 200)) : '');

// ============================================================ tidy up and report
$cleanup();
$pass = count(array_filter($results, fn ($r) => $r[1] === 'PASS')); $fail = count(array_filter($results, fn ($r) => $r[1] === 'FAIL')); $skipN = count(array_filter($results, fn ($r) => $r[1] === 'SKIP'));
$allTc = []; foreach (['B' => 18, 'E' => 6, 'M' => 5, 'S' => 15, 'A' => 19, 'I' => 5, 'X' => 11, 'O' => 5, 'N' => 5, 'C' => 7, 'D' => 4, 'W' => 5, 'R' => 9] as $g => $n) { for ($i = 1; $i <= $n; $i++) { $allTc[] = sprintf('TC-%s%02d', $g, $i); } }
$byHand = array_values(array_filter($allTc, fn ($tc) => !isset($covered[$tc]) || in_array($tc, array_column(array_filter($results, fn ($r) => $r[1] === 'SKIP'), 0), true)));
echo "\n" . str_repeat('=', 60) . "\nRESULT: $pass passed, $fail failed, $skipN skipped (of " . count($results) . " checks, covering " . count(array_intersect($allTc, array_keys($covered))) . " of the " . count($allTc) . " test cases)\n";
echo "Your real database '{$cfg['dbname']}' was not touched. The test database and the test server have been removed.\n";
echo "\nStill to do BY HAND (" . count($byHand) . " test cases): " . implode(' ', $byHand) . "\n";
$dir = $root . DIRECTORY_SEPARATOR . 'test-results'; if (!is_dir($dir)) { mkdir($dir, 0777, true); } $stamp = date('Y-m-d_H-i-s');
$fh = fopen($dir . DIRECTORY_SEPARATOR . "results-$stamp.csv", 'w'); fputcsv($fh, ['Test case', 'Result', 'What was checked']); foreach ($results as $r) { fputcsv($fh, $r); } fclose($fh);
$html = '<!DOCTYPE html><meta charset="utf-8"><title>Test results</title><style>body{font-family:Arial;margin:24px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #bbb;padding:5px 8px;font-size:13px;text-align:left}th{background:#2f5d50;color:#fff}.PASS{color:#0a6b2f;font-weight:bold}.FAIL{color:#b00020;font-weight:bold}.SKIP{color:#8a6d00;font-weight:bold}</style>'
    . '<h2>Executive Grooming Studio - automated test results</h2><p>Run on ' . date('l, j F Y H:i') . ' (South African time) &middot; PHP ' . PHP_VERSION . ' &middot; <b>' . $pass . ' passed, ' . $fail . ' failed, ' . $skipN . ' skipped</b></p><table><tr><th>Test case</th><th>Result</th><th>What was checked</th></tr>';
foreach ($results as $r) { $html .= '<tr><td>' . htmlspecialchars($r[0]) . '</td><td class="' . $r[1] . '">' . $r[1] . '</td><td>' . htmlspecialchars($r[2]) . '</td></tr>'; }
$html .= '</table><p>Still to do by hand: ' . htmlspecialchars(implode(' ', $byHand)) . '</p>';
file_put_contents($dir . DIRECTORY_SEPARATOR . "results-$stamp.html", $html);
echo "Report saved: test-results\\results-$stamp.html (open it in a browser and take your screenshot) and .csv\n";
exit($fail > 0 ? 1 : 0);
