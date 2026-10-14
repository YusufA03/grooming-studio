<?php
// Privacy housekeeping for clients' inspiration photos.
//
//   php tools/cleanup_images.php               deletes the photos of appointments that ended more than 30 days ago
//   php tools/cleanup_images.php --days=14     the same, with your own number of days
//   php tools/cleanup_images.php --dry-run     only REPORTS what it would delete
//
// A photo is only needed until the appointment is over, so keeping it forever would be
// keeping people's pictures for no reason. This also removes any stray photo file that
// no booking refers to (for example from a crash). Run it weekly from a scheduled task.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool can only be run from the command line.');
}

require_once __DIR__ . '/../config/timezone.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/ImageUpload.php';

$days   = 30;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--days=(\d{1,4})$/', $arg, $m) && (int) $m[1] >= 1) {
        $days = (int) $m[1];
    } else {
        echo "Unknown option: $arg\nUse --days=NUMBER and/or --dry-run\n";
        exit(2);
    }
}

try {
    $db  = Database::getConnection();
    $cut = (new DateTimeImmutable('now'))->sub(new DateInterval('P' . $days . 'D'))->format('Y-m-d H:i:s');

    // 1. photos of appointments that are over for good
    $old = $db->prepare(
        'SELECT booking_id, inspiration_image FROM Booking
         WHERE inspiration_image IS NOT NULL AND end_time < :cut'
    );
    $old->execute([':cut' => $cut]);
    $removed = 0;
    $clear   = $db->prepare('UPDATE Booking SET inspiration_image = NULL WHERE booking_id = :id');
    foreach ($old->fetchAll() as $row) {
        $file = (string) $row['inspiration_image'];
        echo ($dryRun ? 'would delete ' : 'deleting     ') . "photo of booking #{$row['booking_id']}\n";
        if (!$dryRun) {
            ImageUpload::deleteInspiration($file);
            $clear->execute([':id' => $row['booking_id']]);
        }
        $removed++;
    }

    // 2. stray files that no booking refers to (older than a day, so a photo being saved right now is safe)
    $used = array_flip($db->query('SELECT inspiration_image FROM Booking WHERE inspiration_image IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
    $strays = 0;
    $folder = ImageUpload::inspirationDirectory();
    if (is_dir($folder)) {
        foreach (scandir($folder) as $name) {
            if (!preg_match(ImageUpload::NAME_PATTERN, $name) || isset($used[$name])) {
                continue;
            }
            if (filemtime($folder . '/' . $name) > time() - 86400) {
                continue;
            }
            echo ($dryRun ? 'would delete ' : 'deleting     ') . "stray file $name\n";
            if (!$dryRun) {
                ImageUpload::deleteInspiration($name);
            }
            $strays++;
        }
    }

    echo ($dryRun ? "DRY RUN. " : '') . "Photos of finished appointments (over $days days ago): $removed | stray files: $strays\n";
} catch (Throwable $ex) {
    echo 'FAILED: ' . $ex->getMessage() . "\n";
    exit(1);
}
