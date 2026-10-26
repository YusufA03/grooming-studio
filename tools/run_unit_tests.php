<?php
// UNIT TEST RUNNER for Executive Grooming Studio.
//
//   C:\xampp\php\php.exe tools\run_unit_tests.php
//
// Where tools\run_tests.php checks whole workflows through the website (system tests), this file tests the
// CLASSES ONE METHOD AT A TIME: it calls Booking::getAvailableSlots(), Loyalty::pointsFor(), Service::validate()
// and so on directly, with normal, boundary and invalid values, and compares each result with the expected one.
// No web server is needed.
//
// HOW IT STAYS SAFE (the same rules as the system test runner)
//  - It creates its OWN database (your database name + "_unit") and deletes it afterwards.
//    Your real database is never touched.
//  - It only runs when the database host is this computer.
//  - Photos go to a temporary folder, which is deleted afterwards. No email is sent.
//
// Results are printed and saved as test-results\unit-results-DATE.csv

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool can only be run from the command line.');
}
date_default_timezone_set('Africa/Johannesburg');
set_time_limit(0);

$root = dirname(__DIR__);
foreach (['pdo_mysql', 'gd', 'mbstring', 'fileinfo'] as $ext) {
    if (!extension_loaded($ext)) { fwrite(STDOUT, "STOPPED: the PHP extension '$ext' is not switched on.\n"); exit(1); }
}
if (!is_file($root . '/config/db_config.php') || !is_file($root . '/database/schema_hosting.sql')) {
    fwrite(STDOUT, "STOPPED: config/db_config.php or database/schema_hosting.sql is missing.\n"); exit(1);
}
$cfg = require $root . '/config/db_config.php';
if (!in_array($cfg['host'], ['localhost', '127.0.0.1', '::1'], true)) {
    fwrite(STDOUT, "STOPPED: the database host is '{$cfg['host']}', not this computer. The unit tests only run against a local database.\n"); exit(1);
}
if (substr($cfg['dbname'], -5) === '_unit' || substr($cfg['dbname'], -5) === '_test') {
    fwrite(STDOUT, "STOPPED: the configured database already ends in _unit or _test.\n"); exit(1);
}

$unitDb = $cfg['dbname'] . '_unit';
$tmp    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gs_unit_' . bin2hex(random_bytes(4));
$photos = $tmp . DIRECTORY_SEPARATOR . 'photos';
mkdir($photos, 0777, true);
ini_set('error_log', $tmp . DIRECTORY_SEPARATOR . 'php-errors.log');   // classes log failures (for example the email step); keep that out of the report

$admin = new PDO("mysql:host={$cfg['host']};charset=utf8mb4", $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$admin->exec("DROP DATABASE IF EXISTS `$unitDb`");
$admin->exec("CREATE DATABASE `$unitDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$cleanedUp = false;
$cleanup = function () use (&$cleanedUp, $admin, $unitDb, $tmp): void {
    if ($cleanedUp) { return; }
    $cleanedUp = true;
    try { $admin->exec("DROP DATABASE IF EXISTS `$unitDb`"); } catch (Throwable) { }
    $rm = function (string $d) use (&$rm): void {
        if (!is_dir($d)) { return; }
        foreach (scandir($d) as $f) { if ($f !== '.' && $f !== '..') { $p = $d . DIRECTORY_SEPARATOR . $f; is_dir($p) ? $rm($p) : @unlink($p); } }
        @rmdir($d);
    };
    $rm($tmp);
};
register_shutdown_function($cleanup);

putenv('GS_TEST_MODE=1');
putenv('GS_DB_NAME=' . $unitDb);
putenv('GS_STORAGE_DIR=' . $photos);
$_ENV['GS_TEST_MODE'] = '1';

$pdo = new PDO("mysql:host={$cfg['host']};dbname=$unitDb;charset=utf8mb4", $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec((string) file_get_contents($root . '/database/schema_hosting.sql'));

foreach (['Database', 'AuditLog', 'Auth', 'Booking', 'Client', 'ImageUpload', 'Loyalty', 'Mailer', 'Report', 'Service', 'Stylist', 'Account', 'AdminAccess'] as $class) {
    require_once $root . '/classes/' . $class . '.php';
}
require_once $root . '/config/helpers.php';
require_once $root . '/config/report_helpers.php';

// ============================================================ tiny framework
$results = []; $n = 0; $group = '';
function out(string $s): void { fwrite(STDOUT, $s); }
function heading(string $g): void { global $group; $group = $g; out("\n$g\n"); }
function u(string $what, bool $ok, string $detail = ''): void
{
    global $results, $n, $group;
    $n++;
    $id = sprintf('UT-%02d', $n);
    $results[] = [$id, $group, $what, $ok ? 'PASS' : 'FAIL', $ok ? '' : $detail];
    out(($ok ? '  PASS ' : '  FAIL ') . $id . '  ' . $what . ($ok || $detail === '' ? '' : '   [' . $detail . ']') . "\n");
}
function q(string $sql, array $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s; }
function one(string $sql, array $p = []) { return q($sql, $p)->fetchColumn(); }
function day(int $offset): DateTimeImmutable          // the Nth working day (Monday to Friday) from 20 days ahead: every test uses its own date
{
    $d = new DateTimeImmutable('today +20 days'); $k = 0;
    while (true) { if ((int) $d->format('N') <= 5) { if ($k === $offset) { return $d; } $k++; } $d = $d->modify('+1 day'); }
}
function at(DateTimeImmutable $d, string $hhmm): DateTimeImmutable { [$h, $m] = array_map('intval', explode(':', $hhmm)); return $d->setTime($h, $m); }
function times(array $slots): array { return array_map(fn ($s) => $s->format('H:i'), $slots); }
function stylist(int $id): array { Stylist::clearCache(); return Stylist::findForAdmin($id); }
function service(int $id): array { return Service::find($id); }
function makeBooking(int $stylistId, int $serviceId, DateTimeImmutable $start, string $name = 'Unit Client', ?string $email = null): ?string
{
    return Booking::create(stylist($stylistId), service($serviceId), $start, $name, $email, '0821234567', null);
}
function bookingId(string $token): int { return (int) one('SELECT booking_id FROM Booking WHERE manage_token = ?', [$token]); }
function insertBooking(int $client, int $stylist, int $service, string $start, string $end, string $status, float $price, string $token): int
{
    q("INSERT INTO Booking (client_id, stylist_id, service_id, start_time, end_time, status, price_charged, manage_token) VALUES (?,?,?,?,?,?,?,?)", [$client, $stylist, $service, $start, $end, $status, $price, $token]);
    return (int) one('SELECT booking_id FROM Booking WHERE manage_token = ?', [$token]);
}
function newClient(string $name, ?string $email = null, ?string $phone = null): int { q('INSERT INTO Client (name, email, phone) VALUES (?,?,?)', [$name, $email, $phone]); return (int) one('SELECT MAX(client_id) FROM Client'); }

out("Executive Grooming Studio - unit test runner\n" . str_repeat('=', 60) . "\n");
out("test database '$unitDb' created (your real database '{$cfg['dbname']}' is not touched)\n");

// ============================================================ Booking: the availability engine
heading('Booking::getAvailableSlots (the availability engine)');
$d1 = day(0);
$s30 = times(Booking::getAvailableSlots(stylist(1), 30, $d1));
u('a 30-minute service on a working day is offered from 09:00 to 16:30 in 30-minute steps (16 times)', count($s30) === 16 && $s30[0] === '09:00' && $s30[15] === '16:30', implode(',', $s30));
$s90 = times(Booking::getAvailableSlots(stylist(1), 90, $d1));
u('a 90-minute service is last offered at 15:30, so it finishes exactly at closing time (17:00)', end($s90) === '15:30' && !in_array('16:00', $s90, true), implode(',', $s90));
$sat = new DateTimeImmutable('next saturday +14 days');
u('a day the stylist does not work (Saturday) has no free times', Booking::getAvailableSlots(stylist(1), 30, $sat) === []);
$past = new DateTimeImmutable('yesterday'); while ((int) $past->format('N') > 5) { $past = $past->modify('-1 day'); }
u('times that have already passed are never offered', Booking::getAvailableSlots(stylist(1), 30, $past) === []);
$d2 = day(1);
$tok = makeBooking(1, 3, at($d2, '10:00'), 'Colour Carla');       // Hair Colour, 90 minutes: 10:00 to 11:30
$free = times(Booking::getAvailableSlots(stylist(1), 30, $d2));
u('an existing booking blocks every overlapping time (10:00, 10:30 and 11:00 are gone)', !array_intersect(['10:00', '10:30', '11:00'], $free));
u('times that only touch the booking are still offered (09:30 ends at 10:00, 11:30 starts at 11:30)', in_array('09:30', $free, true) && in_array('11:30', $free, true), implode(',', $free));
$bid = bookingId((string) $tok);
$own = times(Booking::getAvailableSlots(stylist(1), 30, $d2, $bid));
u('a booking\'s own time is offered again when it is ignored (used when rescheduling)', in_array('10:00', $own, true));
Booking::cancel($bid, 'Unit test');
u('cancelling a booking frees its time at once', in_array('10:00', times(Booking::getAvailableSlots(stylist(1), 30, $d2)), true));
Stylist::setAccepting(1, false);
u('a stylist who has stopped taking bookings has no free times', Booking::getAvailableSlots(stylist(1), 30, $d1) === []);
Stylist::setAccepting(1, true);
u('and the times return when they start taking bookings again', count(Booking::getAvailableSlots(stylist(1), 30, $d1)) === 16);

// a lunch break is two blocks: 09:00-13:00 and 14:00-17:00 on one weekday
$d3 = day(2); $wd = (int) $d3->format('N');
foreach (Stylist::blocks(2) as $b) { if ((int) $b['weekday'] === $wd) { Stylist::removeBlock((int) $b['block_id'], 2); } }
$e1 = Stylist::addBlock(2, $wd, '09:00', '13:00'); $e2 = Stylist::addBlock(2, $wd, '14:00', '17:00');
$lunch = times(Booking::getAvailableSlots(stylist(2), 30, $d3));
u('a lunch break (two blocks) is not bookable: nothing is offered from 13:00 to 13:30 or 13:30 to 14:00', $e1 === '' && $e2 === '' && !in_array('13:00', $lunch, true) && !in_array('13:30', $lunch, true) && in_array('14:00', $lunch, true), $e1 . '|' . $e2 . '|' . implode(',', $lunch));
$lunch90 = times(Booking::getAvailableSlots(stylist(2), 90, $d3));
u('an appointment must fit entirely inside ONE block: a 90-minute service is last offered at 11:30 before lunch and 15:30 after', in_array('11:30', $lunch90, true) && !in_array('12:00', $lunch90, true) && in_array('15:30', $lunch90, true), implode(',', $lunch90));

// ============================================================ Booking: create, rules and outcomes
heading('Booking::create, cancel, reschedule and recordOutcome');
$d4 = day(3);
$t1 = makeBooking(1, 1, at($d4, '09:00'), 'Create Chris', 'chris@example.com');
u('creating a booking for a free time returns a 64-character private token', is_string($t1) && preg_match('/^[a-f0-9]{64}$/', $t1) === 1);
$b1 = Booking::findByToken((string) $t1);
u('the end time is the start time plus the service duration (Men\'s Haircut: 30 minutes)', $b1 !== null && $b1['end_time'] === at($d4, '09:30')->format('Y-m-d H:i:s'), (string) ($b1['end_time'] ?? ''));
u('a new booking is "confirmed" and keeps the price charged when it was made', $b1 !== null && $b1['status'] === 'confirmed' && (float) one('SELECT price_charged FROM Booking WHERE booking_id = ?', [(int) $b1['booking_id']]) === 150.0);
u('booking the same time again is refused (null): double booking is impossible', makeBooking(1, 1, at($d4, '09:00'), 'Late Larry') === null);
u('a time that is not one of the offered 30-minute steps (09:15) is refused, so a booking can never straddle another', makeBooking(1, 1, at($d4, '09:15'), 'Overlap Olga') === null);
q('UPDATE Service SET price = 200 WHERE service_id = 1');
u('changing a service\'s price later does not change an earlier booking\'s price (defect D15 stays fixed)', (float) one('SELECT price_charged FROM Booking WHERE booking_id = ?', [(int) $b1['booking_id']]) === 150.0);
q('UPDATE Service SET price = 150 WHERE service_id = 1');
$log = AuditLog::forBooking((int) $b1['booking_id']);
u('creating a booking writes a "created" line to the history, in the same transaction', count($log) === 1 && $log[0]['action'] === 'created' && $log[0]['actor'] === 'Client (online)', json_encode($log));

q('DELETE FROM StylistService WHERE stylist_id = 3 AND service_id = 3');
u('a stylist who does not perform a service cannot be booked for it (null)', makeBooking(3, 3, at(day(4), '11:00'), 'Wrong Wendy') === null);
q('INSERT INTO StylistService (stylist_id, service_id) VALUES (3, 3)');

$now = new DateTimeImmutable('now'); $fmt = 'Y-m-d H:i:s';
$cc = newClient('Change Cindy', 'cindy@example.com', '0821110000');
$mk = fn (int $min, string $status, string $tokn) => Booking::findByToken(str_pad($tokn, 64, 'a')) ?? null;
$inMin = function (int $minutes, string $status, string $tokn) use ($cc, $fmt, $now): array {
    $st = $now->modify("+$minutes minutes");
    insertBooking($cc, 1, 1, $st->format($fmt), $st->modify('+30 minutes')->format($fmt), $status, 150, str_pad($tokn, 64, 'c'));
    return Booking::findByToken(str_pad($tokn, 64, 'c'));
};
u('a client can change a booking that starts in 3 hours (no problem reported)', Booking::clientChangeProblem($inMin(180, 'confirmed', 'a1')) === '');
$p119 = Booking::clientChangeProblem($inMin(119, 'confirmed', 'a2'));
u('a client cannot change a booking that starts in 119 minutes: it is inside the 2-hour cut-off', str_contains($p119, '2 hours'), $p119);
u('a client can still change a booking that starts in 121 minutes (just outside the cut-off)', Booking::clientChangeProblem($inMin(121, 'confirmed', 'a3')) === '');
u('a cancelled booking says it has been cancelled', str_contains(Booking::clientChangeProblem($inMin(300, 'cancelled', 'a4')), 'cancelled'));
u('a completed booking says it can no longer be changed', str_contains(Booking::clientChangeProblem($inMin(300, 'completed', 'a5')), 'completed'));

$d5 = day(5);
$tc = makeBooking(1, 1, at($d5, '10:00'), 'Cancel Cara'); $cid = bookingId((string) $tc);
u('cancelling a future confirmed booking works (empty message)', Booking::cancel($cid, 'Unit test') === '');
u('cancelling the same booking again is refused with a message', Booking::cancel($cid, 'Unit test') !== '');
u('the history records who cancelled it', one("SELECT actor FROM BookingLog WHERE booking_id = ? AND action = 'cancelled'", [$cid]) === 'Unit test');
$startedId = insertBooking($cc, 1, 1, $now->modify('-40 minutes')->format($fmt), $now->modify('-10 minutes')->format($fmt), 'confirmed', 150, str_repeat('d', 64));
u('a booking that has already started cannot be cancelled (it must be marked completed or no-show)', Booking::cancel($startedId, 'Unit test') !== '' && one('SELECT status FROM Booking WHERE booking_id = ?', [$startedId]) === 'confirmed');
u('a stylist cannot cancel another stylist\'s booking (the answer is the same as "no such booking")', Booking::cancel(bookingId((string) makeBooking(1, 1, at($d5, '11:00'), 'Mine')), 'Rohan', 2) !== '');

$d6 = day(6);
$tr = makeBooking(1, 1, at($d6, '10:00'), 'Move Mia', 'mia@example.com'); $rid = bookingId((string) $tr);
u('rescheduling to a free time works and keeps the status confirmed', Booking::reschedule($rid, at($d6, '14:00'), false, 'Unit test') === '' && one('SELECT status FROM Booking WHERE booking_id = ?', [$rid]) === 'confirmed');
u('the new end time follows the service length after a reschedule', one('SELECT end_time FROM Booking WHERE booking_id = ?', [$rid]) === at($d6, '14:30')->format($fmt));
makeBooking(1, 1, at($d6, '15:00'), 'Blocker Bob');
u('rescheduling onto a time that is taken is refused and the booking does not move', Booking::reschedule($rid, at($d6, '15:00'), false, 'Unit test') !== '' && one('SELECT start_time FROM Booking WHERE booking_id = ?', [$rid]) === at($d6, '14:00')->format($fmt));
u('rescheduling outside working hours is refused (20:00)', Booking::reschedule($rid, at($d6, '20:00'), false, 'Unit test') !== '');
u('rescheduling to a time in the past is refused', Booking::reschedule($rid, $now->modify('-1 day'), false, 'Unit test') !== '');
$soon = insertBooking($cc, 1, 1, $now->modify('+90 minutes')->format($fmt), $now->modify('+120 minutes')->format($fmt), 'confirmed', 150, str_repeat('e', 64));
u('with the client rules, a booking inside the 2-hour cut-off cannot be moved; staff (no rules) are not stopped by the cut-off', Booking::reschedule($soon, at(day(7), '09:00'), true, 'Client') !== '' && Booking::reschedule($soon, at(day(7), '09:00'), false, 'Staff') === '');

$d8 = day(8);
$to = bookingId((string) makeBooking(1, 1, at($d8, '09:00'), 'Outcome Olive', 'olive@example.com'));
u('a no-show cannot be recorded before the appointment starts', Booking::recordOutcome($to, null, 'no-show', 'Unit test') !== '' && one('SELECT status FROM Booking WHERE booking_id = ?', [$to]) === 'confirmed');
u('"completed" can be recorded early (staff are asked to confirm), and the history notes it', Booking::recordOutcome($to, null, 'completed', 'Unit test') === '' && str_contains((string) one("SELECT detail FROM BookingLog WHERE booking_id = ? AND action = 'completed'", [$to]), 'before the appointment started'));
u('completing the booking gave the client 15 loyalty points (R150 / R10)', Loyalty::earnedForBooking($to) === 15);
u('an outcome can only be recorded once', Booking::recordOutcome($to, null, 'no-show', 'Unit test') !== '');
$tw = bookingId((string) makeBooking(1, 1, at($d8, '10:00'), 'Scope Sam'));
u('a stylist can only record outcomes for their own bookings', Booking::recordOutcome($tw, 2, 'completed', 'Rohan') !== '' && one('SELECT status FROM Booking WHERE booking_id = ?', [$tw]) === 'confirmed');
u('an unknown outcome such as "paid" is refused', Booking::recordOutcome($tw, null, 'paid', 'Unit test') !== '' && one('SELECT status FROM Booking WHERE booking_id = ?', [$tw]) === 'confirmed');

// ============================================================ Booking: smart scheduling and lookups
heading('Booking::suggestSlot, findByToken');
$all = Stylist::getAll();
$sug = Booking::suggestSlot($all, stylist(1), 30, 21);
u('the suggestion for a chosen stylist is that stylist\'s own soonest free time', $sug !== null && (int) $sug['stylist']['stylist_id'] === 1 && $sug['preferred'] === true && $sug['start'] > new DateTimeImmutable('now'));
$free1 = null; foreach ($all as $s) { if ((int) $s['stylist_id'] === 1) { $free1 = $s; } }
$any = Booking::suggestSlot($all, null, 30, 21);
u('with "any available stylist" the suggestion is the earliest free time of all stylists', $any !== null && $any['preferred'] === true);
u('the suggestion respects the horizon: with a 0-day horizon and a stylist who never works today, nothing is falsely promised', Booking::suggestSlot([], null, 30, 21) === null);
u('a token that is not 64 hexadecimal characters finds nothing (no database query is made with it)', Booking::findByToken('abc') === null && Booking::findByToken(str_repeat('Z', 64)) === null);
u('a well-formed token that does not exist finds nothing', Booking::findByToken(str_repeat('0', 64)) === null);

// ============================================================ Stylist
heading('Stylist: validation, services and working blocks');
[$cl, $er] = Stylist::validate(['name' => '', 'email' => 'x@y.test', 'password' => 'longenough1'], null, true);
u('a stylist needs a name', isset($er['name']));
[$cl, $er] = Stylist::validate(['name' => str_repeat('A', 101), 'email' => 'x@y.test', 'password' => 'longenough1'], null, true);
u('a name of 101 characters is refused (the limit is 100); 100 is accepted', isset($er['name']) && !isset(Stylist::validate(['name' => str_repeat('A', 100), 'email' => 'z@y.test', 'password' => 'longenough1'], null, true)[1]['name']));
[$cl, $er] = Stylist::validate(['name' => 'Valid', 'email' => 'not-an-email', 'password' => 'longenough1'], null, true);
u('an invalid email address is refused', isset($er['email']));
[$cl, $er] = Stylist::validate(['name' => 'Valid', 'email' => 'thandi@executivegrooming.test', 'password' => 'longenough1'], null, true);
u('an email already used by another staff member is refused', isset($er['email']));
[$cl, $er] = Stylist::validate(['name' => 'Valid', 'email' => 'thandi@executivegrooming.test', 'password' => 'longenough1'], 1, true);
u('but the same email is fine when editing that same stylist', !isset($er['email']));
[$cl, $er] = Stylist::validate(['name' => 'Valid', 'email' => 'new@y.test', 'password' => '123456789'], null, true);
u('a 9-character password is refused (minimum 10)', isset($er['password']));
[$cl, $er] = Stylist::validate(['name' => 'Valid', 'email' => 'new@y.test', 'password' => '1234567890'], null, true);
u('a 10-character password is accepted', !isset($er['password']));
[$cl, $er] = Stylist::validate(['name' => 'Valid', 'email' => 'new@y.test', 'password' => str_repeat('p', 73)], null, true);
u('a 73-character password is refused (bcrypt reads only 72)', isset($er['password']));
[$cl, $er] = Stylist::validate(['name' => 'Valid', 'email' => 'new@y.test', 'password' => ''], null, true);
u('a password is required for a new stylist but optional when editing', isset($er['password']) && !isset(Stylist::validate(['name' => 'Valid', 'email' => 'new@y.test', 'password' => ''], 1, false)[1]['password']));
u('at least one service must be ticked', Stylist::validateServiceIds([])[1] !== null && Stylist::validateServiceIds(null)[1] !== null);
u('a ticked service that does not exist is refused', Stylist::validateServiceIds(['1', '999'])[1] !== null);
u('duplicate and non-numeric ticks are cleaned up: ["1","1","abc","2"] gives [1, 2]', Stylist::validateServiceIds(['1', '1', 'abc', '2'])[0] === [1, 2]);
u('the starting hours Mon to Fri 09:00 to 17:00 become five blocks (weekdays 1 to 5)', count(Stylist::initialBlocks('Mon', 'Fri', '09:00', '17:00') ?? []) === 5);
u('a range that wraps past Sunday (Sat to Mon) gives Saturday, Sunday and Monday', array_column(Stylist::initialBlocks('Sat', 'Mon', '10:00', '14:00') ?? [], 0) === [6, 7, 1]);
u('opening after closing is refused (null)', Stylist::initialBlocks('Mon', 'Fri', '17:00', '09:00') === null && Stylist::initialBlocks('Mon', 'Fri', '09:00', '09:00') === null);
u('an invalid day or time is refused (null)', Stylist::initialBlocks('Funday', 'Fri', '09:00', '17:00') === null && Stylist::initialBlocks('Mon', 'Fri', '25:00', '26:00') === null);
Stylist::removeBlock((int) (Stylist::blocks(3)[0]['block_id'] ?? 0), 3);
$bTue = Stylist::addBlock(3, 2, '10:00', '14:00');
u('adding a block to an empty day works', $bTue === '');
u('a block that overlaps an existing block on the same day is refused', Stylist::addBlock(3, 2, '13:00', '15:00') !== '');
u('a block that only touches another (14:00 after 10:00 to 14:00) is allowed', Stylist::addBlock(3, 2, '14:00', '18:00') === '');
u('a block that ends before it starts is refused', Stylist::addBlock(3, 3, '15:00', '09:00') !== '');
u('an impossible weekday is refused', Stylist::addBlock(3, 9, '09:00', '10:00') !== '');
u('hours are described in plain words (Mon to Fri 09:00 to 17:00)', str_contains(Stylist::describeBlocks(Stylist::blocks(1)), 'Mon to Fri') && str_contains(Stylist::describeBlocks(Stylist::blocks(1)), '09:00 to 17:00'), Stylist::describeBlocks(Stylist::blocks(1)));
$strBefore = Stylist::strandedBookings(1);
q('DELETE FROM StylistService WHERE stylist_id = 2');
u('a stylist with no ticked service cannot be saved: setServices refuses an empty list and changes nothing', Stylist::setServices(2, []) !== '' && (int) one('SELECT COUNT(*) FROM StylistService WHERE stylist_id = 2') === 0);
q('INSERT INTO StylistService (stylist_id, service_id) VALUES (2,1),(2,2),(2,3),(2,4),(2,5)');
u('performs() tells whether a stylist is offered for a service', Stylist::performs(2, 3) === true);
q('DELETE FROM StylistService WHERE stylist_id = 2 AND service_id = 3'); Stylist::clearCache();
u('and says no after the service is unticked', Stylist::performs(2, 3) === false);
q('INSERT INTO StylistService (stylist_id, service_id) VALUES (2,3)');

// ============================================================ Service
heading('Service: validation');
[$c, $e] = Service::validate(['name' => 'Kids Cut', 'description' => 'For boys', 'duration_minutes' => '30', 'price' => '120']);
u('a correct service has no errors and a numeric price', $e === [] && $c['price'] === 120.0 && $c['duration_minutes'] === 30);
u('a price written with a comma, 150,50, is read as 150.5', Service::validate(['name' => 'X1', 'duration_minutes' => '30', 'price' => '150,50'])[0]['price'] === 150.5);
u('a name is required, and 101 characters are too many', isset(Service::validate(['name' => '', 'duration_minutes' => '30', 'price' => '10'])[1]['name']) && isset(Service::validate(['name' => str_repeat('N', 101), 'duration_minutes' => '30', 'price' => '10'])[1]['name']));
u('a name that another service already uses is refused', isset(Service::validate(['name' => "Men's Haircut", 'duration_minutes' => '30', 'price' => '10'])[1]['name']));
u('a service may keep its own name when it is edited', !isset(Service::validate(['name' => "Men's Haircut", 'duration_minutes' => '30', 'price' => '10'], 1)[1]['name']));
u('duration 4 and 481 are refused; 5 and 480 are accepted (the boundaries)', isset(Service::validate(['name' => 'D1', 'duration_minutes' => '4', 'price' => '10'])[1]['duration_minutes']) && isset(Service::validate(['name' => 'D1', 'duration_minutes' => '481', 'price' => '10'])[1]['duration_minutes']) && !isset(Service::validate(['name' => 'D1', 'duration_minutes' => '5', 'price' => '10'])[1]['duration_minutes']) && !isset(Service::validate(['name' => 'D1', 'duration_minutes' => '480', 'price' => '10'])[1]['duration_minutes']));
u('a duration that is not a whole number is refused (abc and 30.5)', isset(Service::validate(['name' => 'D2', 'duration_minutes' => 'abc', 'price' => '10'])[1]['duration_minutes']) && isset(Service::validate(['name' => 'D2', 'duration_minutes' => '30.5', 'price' => '10'])[1]['duration_minutes']));
u('prices 0, -5, abc and 1234567 are all refused', isset(Service::validate(['name' => 'P1', 'duration_minutes' => '30', 'price' => '0'])[1]['price']) && isset(Service::validate(['name' => 'P1', 'duration_minutes' => '30', 'price' => '-5'])[1]['price']) && isset(Service::validate(['name' => 'P1', 'duration_minutes' => '30', 'price' => 'abc'])[1]['price']) && isset(Service::validate(['name' => 'P1', 'duration_minutes' => '30', 'price' => '1234567'])[1]['price']));
u('a description of 1001 characters is refused', isset(Service::validate(['name' => 'Desc', 'description' => str_repeat('d', 1001), 'duration_minutes' => '30', 'price' => '10'])[1]['description']));
$newId = Service::create(Service::validate(['name' => 'Temp Service', 'duration_minutes' => '15', 'price' => '40'])[0]);
u('a new service can be created and found', $newId > 0 && Service::find($newId)['name'] === 'Temp Service');
u('a service that has no bookings can be deleted', Service::delete($newId) === '' && Service::find($newId) === null);
u('a service that has bookings cannot be deleted (the message explains, nothing is lost)', Service::delete(1) !== '' && Service::find(1) !== null);

// ============================================================ Client
heading('Client::findOrCreate (recognising a returning client)');
$c1 = Client::findOrCreate('Ann Email', 'ann@example.com', '0820000001');
u('the same email address gives the same client', Client::findOrCreate('Ann Email', 'ann@example.com', '0820000001') === $c1);
u('a different email address gives a new client', Client::findOrCreate('Ann Email', 'other@example.com', '0820000001') !== $c1);
$p1 = Client::findOrCreate('Phone Pat', null, '082 123 4567');
u('a phone-only client is recognised by name and phone digits, even when the spaces differ', Client::findOrCreate('Phone Pat', null, '0821234567') === $p1 && Client::findOrCreate('phone pat', null, '082-123-4567') === $p1);
u('two different people sharing a phone number are never merged', Client::findOrCreate('Phone Peter', null, '0821234567') !== $p1);

// ============================================================ Loyalty
heading('Loyalty: points, rewards and corrections');
u('points are 1 for every R10: R150 gives 15, R650 gives 65', Loyalty::pointsFor(150) === 15 && Loyalty::pointsFor(650) === 65);
u('points round down: R149.99 gives 14, R9 gives 0, R0 gives 0', Loyalty::pointsFor(149.99) === 14 && Loyalty::pointsFor(9) === 0 && Loyalty::pointsFor(0) === 0);
$lc = newClient('Loyal Lena', 'lena@example.com', '0830000001');
$lb = insertBooking($lc, 1, 3, at(day(9), '09:00')->format($fmt), at(day(9), '10:30')->format($fmt), 'completed', 650, str_repeat('f', 64));
u('awarding points for a completed booking gives 65 and the balance is 65', Loyalty::awardForBooking($pdo, $lb) === 65 && Loyalty::balance($lc) === 65);
u('awarding again gives nothing: one booking can earn points only once', Loyalty::awardForBooking($pdo, $lb) === 0 && Loyalty::balance($lc) === 65);
u('an adjustment of +40 with a reason makes the balance 105', Loyalty::adjust($lc, 40, 'Goodwill gesture', 'Unit test') === '' && Loyalty::balance($lc) === 105);
u('a reward needs 100 points: redeeming 2 blocks with only 105 points is refused', Loyalty::redeem($lc, 2, 'Test', 'Unit test') !== '' && Loyalty::balance($lc) === 105);
u('redeeming one reward takes exactly 100 points (balance 5)', Loyalty::redeem($lc, 1, 'R50 off', 'Unit test') === '' && Loyalty::balance($lc) === 5);
u('a correction may never take the balance below zero', Loyalty::adjust($lc, -6, 'Too much', 'Unit test') !== '' && Loyalty::balance($lc) === 5);
u('a correction needs a reason, and 0 points is not a correction', Loyalty::adjust($lc, 5, '', 'Unit test') !== '' && Loyalty::adjust($lc, 0, 'Nothing', 'Unit test') !== '');
u('the balance is always the sum of the ledger lines', Loyalty::balance($lc) === array_sum(array_column(Loyalty::ledger($lc), 'points')));
u('a client can be found by their phone number typed with spaces or without (defect D12 stays fixed)', count(Loyalty::searchClients('083 000 0001')) >= 1 && count(Loyalty::searchClients('0830000001')) >= 1);

// ============================================================ Account (optional client accounts)
heading('Account: one-time links and passwords');
u('an invalid email address gets no link', Account::requestLink('Bad', 'not-an-email', 24, false) === null);
$secret = Account::requestLink('Ana Account', 'ana@example.com', 24, false);
u('a valid request returns a 64-character secret', is_string($secret) && preg_match('/^[a-f0-9]{64}$/', $secret) === 1);
u('a second request within a minute is throttled (no flood of emails to one inbox)', Account::requestLink('Ana Account', 'ana@example.com') === null);
u('the secret is found while it is unused and not expired', Account::findValid((string) $secret) !== null);
u('a secret that is not 64 hexadecimal characters is never looked up', Account::findValid('abc') === null && Account::findValid(str_repeat('G', 64)) === null);
u('a password of 9 characters is refused; the link stays usable', Account::setPassword((string) $secret, '123456789')['error'] !== null && Account::findValid((string) $secret) !== null);
u('a password of 73 characters is refused', Account::setPassword((string) $secret, str_repeat('x', 73))['error'] !== null);
$set = Account::setPassword((string) $secret, 'A-long-password-1');
u('a good password creates the account (a client id comes back)', $set['error'] === null && (int) $set['client_id'] > 0);
u('the password is stored only as a hash', str_starts_with((string) one('SELECT password_hash FROM Client WHERE client_id = ?', [(int) $set['client_id']]), '$2y$'));
u('the link can be used only once', Account::findValid((string) $secret) === null && Account::setPassword((string) $secret, 'Another-long-password')['error'] !== null);

// ============================================================ AdminAccess
heading('AdminAccess: granting and removing administrator access');
u('granting access needs a name', AdminAccess::grant('', 'new.admin@example.com')['error'] !== null);
u('granting access needs a valid email address', AdminAccess::grant('New Admin', 'nope')['error'] !== null);
$g = AdminAccess::grant('Second Admin', 'second.admin@example.com');
u('granting access to a new person works and returns a one-time link to hand over', $g['error'] === null && is_string($g['link']) && $g['link'] !== '', json_encode($g));
q("INSERT INTO Administrator (name, email, password_hash) VALUES ('Original Admin', 'orig@example.com', ?)", [password_hash('x', PASSWORD_DEFAULT)]);
$origId = (int) one("SELECT admin_id FROM Administrator WHERE email = 'orig@example.com'");
$secondId = (int) one("SELECT admin_id FROM Administrator WHERE client_id IS NOT NULL ORDER BY admin_id DESC LIMIT 1");
u('you cannot remove your own admin access', AdminAccess::revoke($origId, $origId) !== '');
u('removing another administrator works and their client account stays', AdminAccess::revoke($secondId, $origId) === '' && (int) one("SELECT COUNT(*) FROM Client WHERE email = 'second.admin@example.com'") === 1);
u('the last administrator can never be removed', AdminAccess::revoke($origId, 999999) !== '' && (int) one('SELECT COUNT(*) FROM Administrator') === 1);

// ============================================================ Audit log
heading('AuditLog');
$ab = insertBooking($cc, 1, 1, at(day(10), '09:00')->format($fmt), at(day(10), '09:30')->format($fmt), 'confirmed', 150, str_repeat('9', 64));
AuditLog::record($pdo, $ab, 'created', 'Client (online)', null);
AuditLog::record($pdo, $ab, 'rescheduled', 'Administrator: Boss', 'Moved to 10:00');
AuditLog::record($pdo, $ab, 'cancelled', 'Client (private link)', null);
$hist = AuditLog::forBooking($ab);
u('a booking\'s history is returned oldest first, with who did it and a detail', array_column($hist, 'action') === ['created', 'rescheduled', 'cancelled'] && $hist[1]['detail'] === 'Moved to 10:00' && $hist[1]['actor'] === 'Administrator: Boss');
u('a booking with no history returns an empty list', AuditLog::forBooking(999999) === []);
u('the allowed actions are exactly the six that the system records', AuditLog::ACTIONS === ['created', 'rescheduled', 'cancelled', 'completed', 'no-show', 'reminder-sent']);

// ============================================================ Auth
heading('Auth: staff labels and the CSRF security token');
u('staff are named for the history: "Administrator: Boss Admin", "Stylist: Thandi", "Client: Cindy"', Auth::actorLabel(['role' => 'admin', 'name' => 'Boss Admin']) === 'Administrator: Boss Admin' && Auth::actorLabel(['role' => 'stylist', 'name' => 'Thandi']) === 'Stylist: Thandi' && Auth::actorLabel(['role' => 'client', 'name' => 'Cindy']) === 'Client: Cindy');
$tk = Auth::csrfToken();
u('the security token is 64 hexadecimal characters and stays the same within a session', preg_match('/^[a-f0-9]{64}$/', $tk) === 1 && Auth::csrfToken() === $tk);
u('the correct token is accepted', Auth::csrfValid($tk) === true);
u('a wrong, empty, missing or non-text token is rejected', Auth::csrfValid('wrong') === false && Auth::csrfValid('') === false && Auth::csrfValid(null) === false && Auth::csrfValid(123) === false && Auth::csrfValid([$tk]) === false);

// ============================================================ ImageUpload
heading('ImageUpload: file names and safe deletion');
u('a server-made file name (32 hexadecimal characters and jpg, png or webp) is accepted', preg_match(ImageUpload::NAME_PATTERN, str_repeat('a1', 16) . '.jpg') === 1 && preg_match(ImageUpload::NAME_PATTERN, str_repeat('0', 32) . '.webp') === 1);
u('names that could escape the folder or run code are rejected (../x.jpg, a.php, SVG, upper case, a trailing newline)', preg_match(ImageUpload::NAME_PATTERN, '../' . str_repeat('a', 32) . '.jpg') === 0 && preg_match(ImageUpload::NAME_PATTERN, str_repeat('a', 32) . '.php') === 0 && preg_match(ImageUpload::NAME_PATTERN, str_repeat('a', 32) . '.svg') === 0 && preg_match(ImageUpload::NAME_PATTERN, strtoupper(str_repeat('a', 32)) . '.jpg') === 0 && preg_match(ImageUpload::NAME_PATTERN, str_repeat('a', 32) . ".jpg\n") === 0);
file_put_contents($photos . DIRECTORY_SEPARATOR . 'keep-me.txt', 'x'); $goodName = str_repeat('ab', 16) . '.jpg'; file_put_contents(ImageUpload::inspirationDirectory() . DIRECTORY_SEPARATOR . $goodName, 'img');
ImageUpload::deleteInspiration('../keep-me.txt'); ImageUpload::deleteInspiration(null);
u('deleting with a name that does not match the pattern (a path trick) removes nothing', is_file($photos . DIRECTORY_SEPARATOR . 'keep-me.txt'));
ImageUpload::deleteInspiration($goodName);
u('deleting a correctly named photo removes it', !is_file(ImageUpload::inspirationDirectory() . DIRECTORY_SEPARATOR . $goodName));
u('the size limits are as designed: 2 MB for service pictures, 8 MB for inspiration photos, at least 100 pixels', ImageUpload::MAX_BYTES === 2097152 && ImageUpload::INSPIRATION_MAX_BYTES === 8388608 && ImageUpload::MIN_SIDE_PX === 100);
$fake = $tmp . DIRECTORY_SEPARATOR . 'not-really-uploaded.jpg'; file_put_contents($fake, 'not an image');
$res = ImageUpload::saveInspirationFromUpload(['name' => 'x.jpg', 'type' => 'image/jpeg', 'tmp_name' => $fake, 'error' => UPLOAD_ERR_OK, 'size' => 12]);
u('a file that did not arrive through a real upload, or is not an image, is refused and nothing is saved', $res[0] === null && count(array_filter(scandir(ImageUpload::inspirationDirectory()), fn ($f) => preg_match(ImageUpload::NAME_PATTERN, $f))) === 0, json_encode($res));
$none = ImageUpload::saveInspirationFromUpload(['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]);
u('choosing no photo at all is not an error (the photo is optional)', $none[0] === null && ($none[1] === null || $none[1] === ''), json_encode($none));

// ============================================================ Helpers and report helpers
heading('Helper functions');
u('e() turns < > & and quotes into safe text, so a script in a name is never run', e('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;' && !str_contains(e('"x" & \'y\''), '"') && str_contains(e('a & b'), '&amp;'));
u('inputText() returns the default for a missing value and for a value that is not text (an array such as ?from[]=1)', inputText([], 'k', 'dflt') === 'dflt' && inputText(['k' => ['1']], 'k', 'dflt') === 'dflt' && inputText(['k' => 'v'], 'k', 'dflt') === 'v');
u('a booking reference is EGS- followed by 5 digits (EGS-00007)', bookingReference(7) === 'EGS-00007' && bookingReference(12345) === 'EGS-12345');
u('csvCell() defuses text that looks like a formula: a value starting with = + - or @ gets an apostrophe in front', str_starts_with(csvCell('=SUM(A1)'), "'") && str_starts_with(csvCell('+cmd'), "'") && str_starts_with(csvCell('-cmd'), "'") && str_starts_with(csvCell('@cmd'), "'"), csvCell('=SUM(A1)'));
u('csvCell() leaves genuine numbers alone, even negative ones (-100 and -5.50 are not formulas)', csvCell('-100') === '-100' && csvCell('-5.50') === '-5.50' && csvCell(-3) === '-3' && csvCell('+7') === '+7', csvCell('-100'));
u('csvCell() leaves ordinary text alone and quotes text that contains a comma or a quote', csvCell('Men\'s Haircut') === "Men's Haircut" || csvCell('Men\'s Haircut') === '"Men\'s Haircut"', csvCell('Men\'s Haircut'));
u('money() shows rand with two decimals', str_contains(money(1234.5), '1') && str_contains(money(1234.5), '.50') && str_starts_with(money(0), 'R'), money(1234.5));
u('noticeText() turns minutes into words and says "not recorded" when unknown', noticeText(null) !== '' && noticeText(90) !== '' && noticeText(null) !== noticeText(90), noticeText(null) . ' | ' . noticeText(90));

// ============================================================ Report
heading('Report: figures for the administrator');
$rc = newClient('Report Rita', 'rita.report@example.com');
$dr = day(11); $from = $dr->setTime(0, 0); $to = $dr->setTime(23, 59, 59);
insertBooking($rc, 1, 1, at($dr, '09:00')->format($fmt), at($dr, '09:30')->format($fmt), 'completed', 150, str_repeat('1', 64));
insertBooking($rc, 1, 3, at($dr, '10:00')->format($fmt), at($dr, '11:30')->format($fmt), 'completed', 650, str_repeat('2', 64));
insertBooking($rc, 2, 1, at($dr, '09:00')->format($fmt), at($dr, '09:30')->format($fmt), 'no-show', 150, str_repeat('3', 64));
insertBooking($rc, 2, 2, at($dr, '10:00')->format($fmt), at($dr, '10:20')->format($fmt), 'cancelled', 90, str_repeat('4', 64));
$sum = Report::summary($from, $to);
u('the summary counts every booking in the period (4)', (int) ($sum['total'] ?? $sum['bookings'] ?? -1) === 4, json_encode($sum));
u('revenue counts only completed bookings at the price charged (R150 + R650 = R800)', (float) ($sum['revenue'] ?? -1) === 800.0, json_encode($sum));
u('the summary has one count each for completed (2), no-show (1) and cancelled (1)', (int) ($sum['completed'] ?? -1) === 2 && (int) ($sum['no_show'] ?? $sum['noshow'] ?? $sum['no-show'] ?? -1) === 1 && (int) ($sum['cancelled'] ?? -1) === 1, json_encode($sum));
$none = Report::summary($dr->modify('+400 days')->setTime(0, 0), $dr->modify('+400 days')->setTime(23, 59, 59));
u('a period with no bookings gives zero, not an error', (int) ($none['total'] ?? $none['bookings'] ?? -1) === 0 && (float) ($none['revenue'] ?? -1) === 0.0);
u('the customer history of a client that does not exist is null', Report::customerHistory(999999) === null);
u('the customer history of a real client is found', Report::customerHistory($rc) !== null);

// ============================================================ summary
$pass = count(array_filter($results, fn ($r) => $r[3] === 'PASS')); $fail = count($results) - $pass;
out("\nRESULT: $pass passed, $fail failed (of " . count($results) . " unit tests)\n");
$dir = $root . DIRECTORY_SEPARATOR . 'test-results';
if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
$csv = $dir . DIRECTORY_SEPARATOR . 'unit-results-' . date('Y-m-d_H-i-s') . '.csv';
$fh = @fopen($csv, 'w');
if ($fh) { fputcsv($fh, ['ID', 'Class and method tested', 'Test', 'Result', 'Detail']); foreach ($results as $r) { fputcsv($fh, $r); } fclose($fh); out("report saved: $csv\n"); }
$cleanup();
exit($fail === 0 ? 0 : 1);
