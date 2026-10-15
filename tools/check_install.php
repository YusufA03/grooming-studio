<?php
// Checks that this copy of the project is complete and set up correctly.
// It only READS things and never changes anything.
//
// Run it from the project folder:   php tools\check_install.php
//
// FIX  = something is wrong and must be put right.
// WARN = something is missing that you probably want, but the site still runs.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool can only be run from the command line.');
}

$root  = dirname(__DIR__);
$fixes = [];   // things that must be put right
$warns = 0;

function line(string $status, string $what, string $detail = ''): void
{
    echo str_pad($status, 5) . ' ' . $what . ($detail !== '' ? '  -> ' . $detail : '') . "\n";
}
function heading(string $title): void
{
    echo "\n" . $title . "\n" . str_repeat('-', strlen($title)) . "\n";
}

echo "Executive Grooming Studio - installation check\n";
echo "Project folder: $root\n";

// ---------------------------------------------------------------- PHP
heading('1. PHP on this computer');
line(version_compare(PHP_VERSION, '8.0.0', '>=') ? 'OK' : 'FIX', 'PHP version ' . PHP_VERSION, version_compare(PHP_VERSION, '8.0.0', '>=') ? '' : 'PHP 8.0 or newer is needed');
foreach (['pdo_mysql' => 'talks to the database', 'mbstring' => 'handles text lengths', 'fileinfo' => 'checks uploaded pictures'] as $ext => $why) {
    $have = extension_loaded($ext);
    line($have ? 'OK' : 'FIX', "extension $ext ($why)", $have ? '' : 'switch it on in php.ini (remove the ; before extension=' . $ext . ') and restart Apache');
    if (!$have) { $fixes[] = "PHP extension $ext is off"; }
}
foreach (['gd' => 'shrinks and cleans pictures', 'exif' => 'turns sideways phone photos upright'] as $ext => $why) {
    $have = extension_loaded($ext);
    line($have ? 'OK' : 'WARN', "extension $ext ($why)", $have ? '' : 'optional: switch it on in php.ini and restart Apache');
    if (!$have) { $warns++; }
}

// ---------------------------------------------------------------- files
heading('2. Project files (is each file the latest version?)');

// path => text that only the LATEST version of the file contains
$files = [
    'classes/Auth.php'                => 'function csrfValid',
    'classes/Booking.php'             => '?string $clientEmail',
    'classes/Client.php'              => '?string $email',
    'classes/Database.php'            => 'timezone.php',
    'classes/ImageUpload.php'         => 'function saveFromUpload',
    'classes/Mailer.php'              => 'this client has no email address',
    'classes/Reminder.php'            => 'c.email IS NOT NULL',
    'classes/Service.php'             => 'function imageUrl',
    'classes/Stylist.php'             => 'function strandedBookings',
    'config/admin_nav.php'            => "'addbooking'",
    'config/helpers.php'              => 'timezone.php',
    'config/timezone.php'             => 'Africa/Johannesburg',
    'public/admin_dashboard.php'      => '(no email)',
    'public/admin_services.php'       => 'multipart/form-data',
    'public/admin_stylists.php'       => 'save_services',
    'public/book.php'                 => 'service-image',
    'public/book_details.php'         => 'Mailer::sendBookingConfirmation',
    'public/booking_confirmed.php'    => 'cancel_booking.php',
    'public/cancel_booking.php'       => 'cancelByToken',
    'public/cron_reminders.php'       => 'is_string',
    'public/index.php'                => 'hero-title',
    'public/login.php'                => 'csrfValid',
    'public/logout.php'               => 'csrfValid',
    'public/reschedule.php'           => 'getAvailableSlots',
    'public/stylist_add_booking.php'  => 'add-booking v2',
    'public/stylist_schedule.php'     => 'stylist_add_booking.php',
    'public/assets/js/theme.js'       => 'egs-theme',
    'public/inspiration_image.php'    => 'photoNotFound',
    'tools/cleanup_images.php'        => 'Privacy housekeeping',
    'tools/backup_db.php'             => 'Makes a backup copy',
    'tools/run_tests.php'             => 'AUTOMATED TEST RUNNER',
    'classes/AuditLog.php'            => 'The audit trail',
    'classes/Account.php'             => 'OPTIONAL client accounts',
    'config/booking_actions.php'      => 'What staff do to a booking',
    'config/report_helpers.php'       => 'Shared pieces of the five management reports',
    'public/admin_report_register.php'      => 'REPORT 1: Bookings register',
    'public/admin_report_takings.php'       => 'REPORT 2: Takings',
    'public/admin_report_stylists.php'      => 'REPORT 3: Stylist activity',
    'public/admin_report_cancellations.php' => 'REPORT 4: Cancellations and no-shows',
    'public/admin_report_customer.php'      => 'REPORT 5: Customer history',
    'public/admin_report_overview.php'      => 'Busy periods and popular services',
    'classes/AdminAccess.php'         => 'giving or taking away admin access',
    'public/register.php'             => 'Create an account - Executive Grooming Studio',
    'public/set_password.php'         => 'Choose your password - Executive Grooming Studio',
    'public/my_appointments.php'      => 'My appointments - Executive Grooming Studio',
    'public/admin_admins.php'         => 'Administrators - Executive Grooming Studio',
    'classes/Loyalty.php'             => 'Loyalty points (Phase 1',
    'classes/Report.php'              => 'administrator\'s reports',
    'public/admin_reports.php'        => 'Reports - Executive Grooming Studio',
    'public/admin_loyalty.php'        => 'Loyalty Points - Executive Grooming Studio',
    'public/admin_booking.php'        => 'Booking History - Executive Grooming Studio',
    'tools/send_reminders.php'        => 'Reminder::sendDue',
    'tools/set_password.php'          => 'password_hash',
];
$outdated = [];
foreach ($files as $path => $marker) {
    $full = $root . '/' . $path;
    if (!is_file($full)) {
        line('FIX', $path, 'MISSING: this file is not in the project');
        $fixes[] = "missing file $path";
        continue;
    }
    if (strpos((string) file_get_contents($full), $marker) === false) {
        line('FIX', $path, 'OUT OF DATE: replace it with the newest version');
        $fixes[] = "replace $path";
        $outdated[] = $path;
        continue;
    }
    line('OK', $path);
}

// the stylesheet may be switched off by renaming it
$cssOn  = $root . '/public/assets/css/style.css';
$cssOff = $root . '/public/assets/css/style.css.off';
$cssFile = is_file($cssOn) ? $cssOn : (is_file($cssOff) ? $cssOff : null);
if ($cssFile === null) {
    line('FIX', 'public/assets/css/style.css', 'MISSING');
    $fixes[] = 'missing the stylesheet';
} else {
    $css = (string) file_get_contents($cssFile);
    $missingCss = [];
    foreach (['.schedule-table' => 'schedule/table styles', '.filter-form' => 'admin filter styles', '.slot form' => 'reschedule styles', '.service-image' => 'picture styles', '.service-thumb' => 'picture thumbnail styles', '.admin-nav' => 'admin menu styles', '.hero-title' => 'the new design (home page hero)', '.theme-toggle' => 'light/dark switch button'] as $needle => $what) {
        if (strpos($css, $needle) === false) { $missingCss[] = $what; }
    }
    $name = 'public/assets/css/' . basename($cssFile) . ($cssFile === $cssOff ? '  (switched OFF: renamed .off)' : '');
    if ($missingCss) {
        line('WARN', $name, 'missing: ' . implode(', ', $missingCss) . ' (paste the CSS blocks at the bottom of the file)');
        $warns++;
    } else {
        line('OK', $name);
    }
}

// does every page load the light/dark switch?
$noSwitch = [];
foreach (glob($root . '/public/*.php') as $page) {
    $text = (string) file_get_contents($page);
    if (strpos($text, 'assets/css/style.css') !== false && strpos($text, 'assets/js/theme.js') === false) {
        $noSwitch[] = 'public/' . basename($page);
    }
}
if (count($noSwitch) === 0) {
    line('OK', 'every page loads the light/dark switch');
} else {
    line('FIX', count($noSwitch) . ' page(s) do not load the light/dark switch', 'run:  php tools\\apply_theme.php');
    $fixes[] = 'run   php tools\\apply_theme.php   (adds the light/dark switch to ' . count($noSwitch) . ' pages)';
}

// ---------------------------------------------------------------- settings
heading('3. Settings files');
foreach ([
    'config/db_config.php'   => ['FIX',  'database login settings: copy db_config.example.php to this name'],
    'config/mail_config.php' => ['WARN', 'email settings: copy mail_config.example.php to this name (no emails will be sent without it)'],
    'config/cron_config.php' => ['WARN', 'secret key for the reminder web address (only needed if a web timer will trigger reminders)'],
    'vendor/autoload.php'    => ['WARN', 'PHPMailer is not installed: run   composer install   in the project folder (no emails without it)'],
] as $path => [$level, $why]) {
    if (is_file($root . '/' . $path)) {
        line('OK', $path);
    } else {
        line($level, $path, $why);
        if ($level === 'FIX') { $fixes[] = "missing $path"; } else { $warns++; }
    }
}

// is email set up for REAL clients?
if (is_file($root . '/config/mail_config.php')) {
    $mailCfg = require $root . '/config/mail_config.php';
    $local   = in_array($mailCfg['host'] ?? '', ['localhost', '127.0.0.1'], true) && (int) ($mailCfg['port'] ?? 0) === 1025;
    if ($local) {
        line('WARN', 'email goes to Mailpit (the local test mailbox)', 'fine for testing; for REAL clients switch to Gmail or Brevo in config/mail_config.php');
        $warns++;
    } else {
        line('OK', 'email uses a real mail server (' . ($mailCfg['host'] ?? '?') . ':' . ($mailCfg['port'] ?? '?') . ')');
    }
    if (stripos((string) ($mailCfg['site_url'] ?? ''), 'localhost') !== false) {
        line('WARN', 'site_url still says localhost', 'links inside emails only work on this computer; on the live site use its real address');
        $warns++;
    }
}

// ---------------------------------------------------------------- database
heading('4. Database');
$db = null;
if (!extension_loaded('pdo_mysql') || !is_file($root . '/config/db_config.php') || !is_file($root . '/classes/Database.php')) {
    line('WARN', 'database checks skipped', 'needs the pdo_mysql extension, config/db_config.php and classes/Database.php');
    $warns++;
} else {
    require_once $root . '/classes/Database.php';
    try {
        $db = Database::getConnection();
        line('OK', 'connected to the database');
    } catch (Throwable $ex) {
        line('FIX', 'could not connect to the database', 'is MySQL started in XAMPP? (' . substr($ex->getMessage(), 0, 80) . ')');
        $fixes[] = 'cannot connect to the database';
    }
}
if ($db !== null) {
    $col = function (string $table, string $column) use ($db): ?array {
        $q = $db->prepare('SELECT is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c');
        $q->execute([':t' => $table, ':c' => $column]);
        $r = $q->fetch();
        return $r === false ? null : $r;
    };
    foreach (['Client', 'Stylist', 'Service', 'Booking', 'Administrator', 'BookingLog', 'LoyaltyLedger', 'AccountToken', 'StylistService', 'WorkingBlock'] as $table) {
        $q = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t');
        $q->execute([':t' => $table]);
        $has = (int) $q->fetchColumn() > 0;
        line($has ? 'OK' : 'FIX', "table $table", $has ? '' : (in_array($table, ['BookingLog', 'LoyaltyLedger', 'AccountToken', 'StylistService', 'WorkingBlock'], true) ? 'run database/migration_2026_10_15.sql and migration_2026_10_16.sql in phpMyAdmin (Import tab). It is safe to run twice and deletes nothing.' : 'missing: import database/schema.sql'));
        if (!$has) { $fixes[] = "missing table $table"; }
    }
    $r = $col('Service', 'image');
    line($r ? 'OK' : 'FIX', 'Service.image column (for service pictures)', $r ? '' : 'run in phpMyAdmin:  ALTER TABLE Service ADD COLUMN image VARCHAR(255) NULL;');
    if (!$r) { $fixes[] = 'database: add Service.image'; }
    $r = $col('Client', 'email');
    $ok = $r && $r['is_nullable'] === 'YES';
    line($ok ? 'OK' : 'FIX', 'Client.email may be empty (for phone bookings)', $ok ? '' : 'run in phpMyAdmin:  ALTER TABLE Client MODIFY email VARCHAR(150) NULL;');
    if (!$ok) { $fixes[] = 'database: let Client.email be empty'; }
    foreach (['Booking' => ['manage_token', 'reminder_sent', 'allergies', 'price_charged'], 'Stylist' => ['email', 'password_hash', 'accepting_bookings']] as $table => $cols) {
        foreach ($cols as $c) {
            $r = $col($table, $c);
            line($r ? 'OK' : 'FIX', "$table.$c", $r ? '' : 'missing: your database is older than the project. run database/migration_2026_10_15.sql and migration_2026_10_16.sql in phpMyAdmin (Import tab). It is safe to run twice and deletes nothing.');
            if (!$r) { $fixes[] = "database: $table.$c missing"; }
        }
    }
    try {
        $admins = (int) $db->query('SELECT COUNT(*) FROM Administrator')->fetchColumn();
        line($admins > 0 ? 'OK' : 'WARN', "administrator accounts: $admins", $admins > 0 ? '' : 'create one:  php tools\\set_password.php');
        if ($admins === 0) { $warns++; }
        $unset = (int) $db->query("SELECT COUNT(*) FROM Stylist WHERE password_hash = 'SET_LATER'")->fetchColumn();
        line($unset === 0 ? 'OK' : 'WARN', "stylists without a password yet: $unset", $unset === 0 ? '' : 'they cannot log in: set it on the admin Stylists page');
        if ($unset > 0) { $warns++; }
    } catch (Throwable $ex) {
        line('WARN', 'could not count the accounts', substr($ex->getMessage(), 0, 80));
        $warns++;
    }
}

// ---------------------------------------------------------------- folders
heading('5. Folders');
$up = $root . '/public/uploads/services';
if (is_dir($up)) {
    line(is_writable($up) ? 'OK' : 'FIX', 'public/uploads/services', is_writable($up) ? 'writable' : 'exists but the server cannot write to it');
    if (!is_writable($up)) { $fixes[] = 'uploads folder is not writable'; }
} else {
    $parent = $root . '/public';
    line(is_writable($parent) ? 'OK' : 'WARN', 'public/uploads/services', is_writable($parent) ? 'does not exist yet; it is created on the first upload' : 'does not exist and public/ is not writable: pictures cannot be saved');
    if (!is_writable($parent)) { $warns++; }
}

// ---------------------------------------------------------------- summary
echo "\n" . str_repeat('=', 60) . "\n";
if (count($fixes) === 0) {
    echo "RESULT: everything required is in place.";
    echo $warns > 0 ? " ($warns optional item" . ($warns === 1 ? '' : 's') . " marked WARN above.)\n" : "\n";
    exit(0);
}
echo 'RESULT: ' . count($fixes) . " thing" . (count($fixes) === 1 ? '' : 's') . " to put right:\n";
foreach ($fixes as $f) {
    echo "  - $f\n";
}
if ($warns > 0) {
    echo "(plus $warns optional item" . ($warns === 1 ? '' : 's') . " marked WARN above)\n";
}
exit(1);
