<?php
// Makes a backup copy of the WHOLE database (every table and every row).
//
//   php tools/backup_db.php                  saves  backups/grooming-studio-YYYY-MM-DD-HHMM.sql.gz
//   php tools/backup_db.php --keep=14        keeps the last 14 days of backups (default 7)
//   php tools/backup_db.php --dir=FOLDER     saves somewhere else
//
// Run it once a day from a scheduled task. To RESTORE a backup, open phpMyAdmin, click your
// database, choose Import, and pick the .sql.gz file (phpMyAdmin reads .gz files directly).
//
// How it stays safe:
//  - the copy is taken in one consistent "snapshot", so it is correct even while people are booking
//  - it reads the data in small pieces, so a big database never runs out of memory
//  - the file is written under a temporary name and only given its real name once it is finished
//    and has been read back successfully, so a half-written backup is never mistaken for a good one
//  - the backup folder is outside the public web folder, closed to the web, and the files are
//    readable only by their owner (they contain clients' details)
//  - old backups are deleted only after a new one succeeded

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool can only be run from the command line.');
}

require_once __DIR__ . '/../config/timezone.php';
require_once __DIR__ . '/../classes/Database.php';

$root     = dirname(__DIR__);
$folder   = $root . '/backups';
$keepDays = 7;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--keep=(\d{1,4})$/', $arg, $m) && (int) $m[1] >= 1) {
        $keepDays = (int) $m[1];
    } elseif (preg_match('/^--dir=(.+)$/', $arg, $m)) {
        $folder = rtrim($m[1], '/\\');
    } else {
        echo "Unknown option: $arg\nUse --keep=DAYS and/or --dir=FOLDER\n";
        exit(2);
    }
}

const BACKUP_NAME_PATTERN = '/^grooming-studio-\d{4}-\d{2}-\d{2}-\d{4}\.sql(\.gz)?$/';

function fail(string $message, ?string $partial = null): void
{
    if ($partial !== null && is_file($partial)) {
        @unlink($partial);
    }
    echo "BACKUP FAILED: $message\n";
    exit(1);
}

// ---- the folder ------------------------------------------------------------
if (!is_dir($folder) && !@mkdir($folder, 0700, true) && !is_dir($folder)) {
    fail("could not create the backup folder ($folder)");
}
if (!is_writable($folder)) {
    fail("the backup folder is not writable ($folder)");
}
if (!is_file($folder . '/.htaccess')) {
    @file_put_contents($folder . '/.htaccess', "# Backups must never be opened from the web.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
}

$useGzip  = function_exists('gzopen');
$stamp    = date('Y-m-d-Hi');
$final    = $folder . '/grooming-studio-' . $stamp . ($useGzip ? '.sql.gz' : '.sql');
$partial  = $final . '.partial';
if (is_file($final)) {
    fail("a backup with this minute's name already exists ($final); wait a minute and try again");
}

$handle = $useGzip ? @gzopen($partial, 'wb6') : @fopen($partial, 'wb');
if ($handle === false) {
    fail('could not create the backup file', $partial);
}
$write = function (string $text) use ($handle, $useGzip): void {
    $ok = $useGzip ? gzwrite($handle, $text) : fwrite($handle, $text);
    if ($ok === false) {
        throw new RuntimeException('writing to the backup file failed (disk full?)');
    }
};

$rowCounts = [];
try {
    $db = Database::getConnection();
    $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

    $dbName  = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    $version = (string) $db->query('SELECT VERSION()')->fetchColumn();
    $tables  = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    if (count($tables) === 0) {
        throw new RuntimeException('the database has no tables');
    }

    $write("-- Executive Grooming Studio: database backup\n");
    $write('-- Taken: ' . date('Y-m-d H:i:s') . " (South African time)\n");
    $write("-- Database: $dbName | server: $version\n");
    $write("-- Restore: phpMyAdmin > your database > Import > choose this file.\n\n");
    $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

    // Read big tables a row at a time instead of loading them all into memory.
    $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

    foreach ($tables as $table) {
        $quoted = '`' . str_replace('`', '``', $table) . '`';

        $create = $db->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM);
        $write("DROP TABLE IF EXISTS $quoted;\n" . $create[1] . ";\n\n");

        $count   = 0;
        $batch   = [];
        $columns = null;
        $rows    = $db->query('SELECT * FROM ' . $quoted);
        $flush   = function () use (&$batch, &$columns, $quoted, $write): void {
            if (count($batch) > 0) {
                $write('INSERT INTO ' . $quoted . ' (' . $columns . ') VALUES' . "\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        };
        while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($columns === null) {
                $columns = implode(',', array_map(fn ($c) => '`' . str_replace('`', '``', (string) $c) . '`', array_keys($row)));
            }
            $values = [];
            foreach ($row as $value) {
                $values[] = ($value === null) ? 'NULL' : $db->quote((string) $value);
            }
            $batch[] = '(' . implode(',', $values) . ')';
            $count++;
            if (count($batch) >= 100) {
                $flush();
            }
        }
        $rows->closeCursor();
        $flush();
        $write("\n");
        $rowCounts[$table] = $count;
    }
    $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    $db->rollBack();   // we only READ; this just ends the snapshot

    $write("SET UNIQUE_CHECKS = 1;\nSET FOREIGN_KEY_CHECKS = 1;\n-- Backup completed " . date('Y-m-d H:i:s') . "\n");
    $useGzip ? gzclose($handle) : fclose($handle);
} catch (Throwable $ex) {
    $useGzip ? @gzclose($handle) : @fclose($handle);
    fail($ex->getMessage(), $partial);
}

// ---- read it back: the file must open cleanly and end with the "completed" line ----
$check = $useGzip ? @gzopen($partial, 'rb') : @fopen($partial, 'rb');
if ($check === false) {
    fail('the finished file could not be read back', $partial);
}
$tail = '';
while (!($useGzip ? gzeof($check) : feof($check))) {
    $chunk = $useGzip ? gzread($check, 65536) : fread($check, 65536);
    if ($chunk === false) {
        fail('the finished file is damaged', $partial);
    }
    $tail = substr($tail . $chunk, -300);
}
$useGzip ? gzclose($check) : fclose($check);
if (strpos($tail, '-- Backup completed') === false) {
    fail('the finished file is incomplete', $partial);
}

if (!@rename($partial, $final)) {
    fail('could not give the backup its final name', $partial);
}
@chmod($final, 0600);

echo "Backup saved: $final (" . number_format(filesize($final) / 1024, 1) . " KB)\n";
foreach ($rowCounts as $table => $count) {
    echo "  $table: $count rows\n";
}

// ---- tidy up: delete OLD backups, but never the newest 3, and only after a success ----
$all = [];
foreach (scandir($folder) as $name) {
    if (preg_match(BACKUP_NAME_PATTERN, $name)) {
        $all[] = $name;
    }
}
rsort($all);                                   // newest first (the names contain the date)
$limit   = time() - $keepDays * 86400;
$removed = 0;
foreach (array_slice($all, 3) as $name) {
    if (filemtime($folder . '/' . $name) < $limit) {
        @unlink($folder . '/' . $name);
        $removed++;
    }
}
echo "Backups kept: " . (count($all) - $removed) . " (anything older than $keepDays days is removed; the newest 3 are always kept)" . ($removed ? " | removed $removed old" : '') . "\n";
exit(0);
