<?php
// RESET TEST DATA: clears the CLIENTS and BOOKINGS so you can start a fresh round of testing.
//
//   php tools/reset_test_data.php --dry-run        only SHOWS what would be deleted (changes nothing)
//   php tools/reset_test_data.php                  does it, after a backup and after you type the database name
//   php tools/reset_test_data.php --keep-clients   clears the bookings only, and keeps every client
//
// WHAT IS DELETED:  all bookings, the booking history (audit trail), all loyalty points, any waiting
//                   "choose your password" links, the clients' private photos, and every client record.
// WHAT IS KEPT:     services, stylists (with the services they perform and their working hours),
//                   administrators, and any client account that is ALSO an administrator (deleting it
//                   would take that person's admin access away).
//
// HOW IT STAYS SAFE:
//  - it can only be run from the command line, never from a web page
//  - it first shows exactly what it will delete and from WHICH database
//  - it does nothing until you type the name of that database (so you cannot clear the wrong one by accident)
//  - it takes a full backup first, and stops without deleting anything if the backup fails
//  - everything is deleted in one transaction: all of it, or none of it
//  - to undo it afterwards, open phpMyAdmin, click the database, choose Import, and pick the backup it made

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool can only be run from the command line.');
}

require_once __DIR__ . '/../config/timezone.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/ImageUpload.php';

$root        = dirname(__DIR__);
$dryRun      = false;
$keepClients = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--keep-clients') {
        $keepClients = true;
    } else {
        echo "Unknown option: $arg\nUse --dry-run and/or --keep-clients\n";
        exit(2);
    }
}

try {
    $db = Database::getConnection();
} catch (Throwable $ex) {
    echo "Could not connect to the database: " . $ex->getMessage() . "\n";
    exit(1);
}
$dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
$count  = static fn (string $sql): int => (int) $db->query($sql)->fetchColumn();

$linkedClients = $count('SELECT COUNT(*) FROM Client WHERE client_id IN (SELECT client_id FROM Administrator WHERE client_id IS NOT NULL)');
$clientsTotal  = $count('SELECT COUNT(*) FROM Client');
$clientsGone   = $keepClients ? 0 : $clientsTotal - $linkedClients;

$photoDir   = ImageUpload::inspirationDirectory();
$photoFiles = [];
if (is_dir($photoDir)) {
    foreach (scandir($photoDir) as $f) {
        if (preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $f)) {
            $photoFiles[] = $photoDir . DIRECTORY_SEPARATOR . $f;
        }
    }
}

echo "RESET TEST DATA\n";
echo str_repeat('=', 60) . "\n";
echo "Database: $dbName\n\n";
echo "This WILL DELETE:\n";
printf("  %5d bookings\n", $count('SELECT COUNT(*) FROM Booking'));
printf("  %5d booking history lines (audit trail)\n", $count('SELECT COUNT(*) FROM BookingLog'));
printf("  %5d loyalty point lines\n", $count('SELECT COUNT(*) FROM LoyaltyLedger'));
printf("  %5d waiting \"choose your password\" links\n", $count('SELECT COUNT(*) FROM AccountToken'));
printf("  %5d clients%s\n", $clientsGone, $keepClients ? ' (none: --keep-clients)' : '');
printf("  %5d private photos\n", count($photoFiles));
echo "\nThis will KEEP:\n";
printf("  %5d services\n", $count('SELECT COUNT(*) FROM Service'));
printf("  %5d stylists (with their services and working hours)\n", $count('SELECT COUNT(*) FROM Stylist'));
printf("  %5d administrators\n", $count('SELECT COUNT(*) FROM Administrator'));
if (!$keepClients) {
    printf("  %5d client accounts that are also administrators\n", $linkedClients);
}
echo "\n";

if ($dryRun) {
    echo "Dry run: nothing was changed.\n";
    exit(0);
}

// ---- the confirmation: type the name of THIS database ----
echo "A full backup is taken first. To go ahead, type the name of this database ($dbName) and press Enter.\n";
echo "Anything else cancels: ";
$typed = fgets(STDIN);
if ($typed === false || trim($typed) !== $dbName) {
    echo "\nCancelled. Nothing was deleted.\n";
    exit(1);
}

// ---- the backup: if it fails, nothing is deleted ----
$cmd = [PHP_BINARY, $root . '/tools/backup_db.php', '--keep=30'];
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$out  = is_resource($proc) ? stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]) : '';
$code = is_resource($proc) ? proc_close($proc) : 1;
if ($code !== 0 || strpos($out, 'Backup saved') === false) {
    echo "\nThe backup FAILED, so nothing was deleted:\n$out\n";
    exit(1);
}
preg_match('/Backup saved: (.+?) \(/', $out, $m);
echo "\nBackup saved: " . ($m[1] ?? '(see the backups folder)') . "\n";

// ---- the deletion: all of it or none of it ----
$db->beginTransaction();
try {
    $db->exec('DELETE FROM LoyaltyLedger');
    $db->exec('DELETE FROM BookingLog');
    $db->exec('DELETE FROM Booking');
    $db->exec('DELETE FROM AccountToken');
    if (!$keepClients) {
        $db->exec('DELETE FROM Client WHERE client_id NOT IN (SELECT client_id FROM Administrator WHERE client_id IS NOT NULL)');
    }
    $db->commit();
} catch (Throwable $ex) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "\nSomething went wrong, so NOTHING was deleted: " . $ex->getMessage() . "\n";
    exit(1);
}

// booking numbers start again from 1 (EGS-00001), so a fresh round of tests is easy to read
foreach (['Booking', 'BookingLog', 'LoyaltyLedger', 'AccountToken', 'Client'] as $table) {
    $db->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1");
}

$removed = 0;
foreach ($photoFiles as $file) {
    if (@unlink($file)) {
        $removed++;
    }
}

echo "\nDone.\n";
printf("  bookings left: %d | clients left: %d | photos removed: %d\n", $count('SELECT COUNT(*) FROM Booking'), $count('SELECT COUNT(*) FROM Client'), $removed);
echo "  services, stylists, working hours and administrators were not touched.\n";
echo "  To undo: phpMyAdmin > your database > Import > the backup named above.\n";
exit(0);
