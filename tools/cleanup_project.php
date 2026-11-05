<?php
// CLEAN UP UNNECESSARY FILES: finds the files a project collects over time that nothing needs.
//   cd C:\xampp\htdocs\grooming-studio
//C:\xampp\php\php.exe tools\cleanup_project.php
//   php tools/cleanup_project.php                  only SHOWS what it would clean up. Nothing is changed.
//   php tools/cleanup_project.php --delete         moves them to a recovery folder next to the project (after you confirm)
//   php tools/cleanup_project.php --delete --permanent   really deletes them instead (after you type PERMANENT)
//   php tools/cleanup_project.php --helpers        also lists the temporary helper pages that must not stay on the LIVE site
//   add --yes to skip the question (for scripts)
//
// WHAT IT LOOKS FOR
//   - old test reports (the test runners make new ones every time)
//   - computer junk: .DS_Store, Thumbs.db, desktop.ini, Word lock files (~$...), editor leftovers (*~, *.swp, *.tmp)
//   - leftover copies: *.bak, *.orig, *.rej, *.old, "x - Copy.php", "x (1).php", x_old.php, x_backup.php, copy of x
//   - log files (error_log, *.log) and downloaded .zip files left in the project folder
//   - the stray files from earlier copy-and-paste slips that install_update.php also knows about
//   - empty folders nothing needs
//
// WHAT IT NEVER TOUCHES (these are protected, whatever their name)
//   - every file listed in MANIFEST.json (the official project files)
//   - config/db_config.php, config/mail_config.php, config/cron_config.php and the *.example.php files
//   - the .git folder, the vendor folder, storage/ (clients' photos), backups/ (database backups)
//   - uploaded service pictures and anything else in public/assets that is not obvious junk
//   - .gitignore, README.md, composer files
//   Files that are in a code folder but NOT in MANIFEST.json are only LISTED for you to look at ("REVIEW"). They are never moved.
//
// HOW IT STAYS SAFE
//   - by default it changes nothing; you must add --delete, and then confirm by typing CLEAN
//   - --delete MOVES the files (keeping their folder structure) into "<project>-cleanup-DATE" next to the project,
//     so any file can be put back by copying it back. Only --permanent really deletes.
//   - it only runs from the command line, and only inside a folder that looks like this project

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool can only be run from the command line.');
}

$root = dirname(__DIR__);
$self = 'tools/cleanup_project.php';

$doDelete = $permanent = $helpers = $yes = false;
foreach (array_slice($argv, 1) as $arg) {
    switch ($arg) {
        case '--delete':    $doDelete = true; break;
        case '--permanent': $permanent = true; break;
        case '--helpers':   $helpers = true; break;
        case '--yes':       $yes = true; break;
        default:
            echo "Unknown option: $arg\nUse --delete, --permanent, --helpers and/or --yes\n";
            exit(2);
    }
}
if ($permanent && !$doDelete) {
    echo "--permanent only makes sense together with --delete.\n";
    exit(2);
}

foreach (['classes', 'public', 'tools'] as $dir) {
    if (!is_dir($root . '/' . $dir)) {
        echo "STOPPED: this does not look like the Executive Grooming Studio project folder (no $dir folder in $root).\n";
        exit(1);
    }
}
$manifestFile = $root . '/MANIFEST.json';
if (!is_file($manifestFile)) {
    echo "STOPPED: MANIFEST.json is missing, so the tool cannot tell which files are official. Extract the latest bundle over the project first.\n";
    exit(1);
}
$manifest = json_decode((string) file_get_contents($manifestFile), true);
if (!is_array($manifest) || !isset($manifest['files']) || !is_array($manifest['files'])) {
    echo "STOPPED: MANIFEST.json is damaged. Download the bundle again.\n";
    exit(1);
}

// ------------------------------------------------------------ what is protected
$official = [];
foreach (array_keys($manifest['files']) as $f) { $official[str_replace('\\', '/', (string) $f)] = true; }
$optional = array_map(fn ($f) => str_replace('\\', '/', (string) $f), $manifest['optional'] ?? []);
$settings = ['config/db_config.php', 'config/mail_config.php', 'config/cron_config.php'];
$skipDirs = ['.git', 'vendor', 'storage', 'backups', 'node_modules'];                  // never even looked into
$keepDirs = ['classes', 'config', 'database', 'public', 'public/assets', 'public/assets/css', 'public/assets/js', 'public/assets/img',
             'storage', 'storage/inspiration', 'tools', 'test-results', 'backups', 'vendor'];
$helperPages = ['public/server_check.php', 'public/email_test.php', 'public/cron_reminders.php', 'tools/reset_test_data.php'];
$strayFiles = ['public/Service.php' => 'classes/Service.php', 'public/assets/stylist_schedule.php' => 'public/stylist_schedule.php'];
$strayFolders = ['tools/backups'];

function norm(string $p): string { return str_replace('\\', '/', $p); }
function human(int $bytes): string
{
    if ($bytes < 1024) { return $bytes . ' B'; }
    if ($bytes < 1048576) { return round($bytes / 1024, 1) . ' KB'; }
    return round($bytes / 1048576, 1) . ' MB';
}

// What kind of leftover is this file? Returns a category, or null if it is not recognisably unnecessary.
function classify(string $rel): ?string
{
    $base = basename($rel);
    if (strpos($rel, 'test-results/') === 0 && $base !== '.gitkeep') {
        return 'Test reports (every run of a test runner makes new ones)';
    }
    if (in_array($base, ['.DS_Store', 'Thumbs.db', 'desktop.ini'], true) || strncmp($base, '~$', 2) === 0) {
        return 'Computer junk and Word lock files';
    }
    if (preg_match('/(~|\.swp|\.swo|\.tmp|\.temp|\.bak|\.orig|\.rej|\.old)$/i', $base)) {
        return 'Leftover copies and editor files';
    }
    if (preg_match('/ - copy( \(\d+\))?(\.[A-Za-z0-9]+)?$/i', $base) || preg_match('/ \(\d+\)\.[A-Za-z0-9]+$/', $base)
        || preg_match('/^copy of /i', $base) || preg_match('/[_-](old|backup|copy|bak)\.(php|css|js|sql|txt|html)$/i', $base)) {
        return 'Leftover copies and editor files';
    }
    if ($base === 'error_log' || $base === 'php_errors.log' || preg_match('/\.log$/i', $base)) {
        return 'Log files (made again as needed)';
    }
    if (preg_match('/\.zip$/i', $base) && strpos($rel, '/') === false) {
        return 'Downloaded zip files left in the project folder';
    }
    return null;
}

// ------------------------------------------------------------ scan
$found = [];        // category => [[relative path, size], ...]
$review = [];       // files in code folders that are not in MANIFEST.json
$emptyDirs = [];
$candidateSet = [];

$add = function (string $cat, string $rel, int $size) use (&$found, &$candidateSet): void {
    if (isset($candidateSet[$rel])) { return; }
    $candidateSet[$rel] = true;
    $found[$cat][] = [$rel, $size];
};

$stack = [''];
while ($stack) {
    $relDir = array_pop($stack);
    $abs = $root . ($relDir === '' ? '' : '/' . $relDir);
    $names = @scandir($abs);
    if ($names === false) { continue; }
    $hasContent = false;
    foreach ($names as $name) {
        if ($name === '.' || $name === '..') { continue; }
        $hasContent = true;
        $rel = ($relDir === '' ? '' : $relDir . '/') . $name;
        if (is_dir($abs . '/' . $name)) {
            if (in_array($name, $skipDirs, true) && $relDir === '') { continue; }
            if ($relDir === 'tools' && $name === 'backups') { continue; }       // reported below as a stray folder
            $stack[] = $rel;
            continue;
        }
        $size = (int) @filesize($abs . '/' . $name);
        if ($rel === $self) { continue; }
        $isHelper = in_array($rel, $helperPages, true);
        if ($helpers && $isHelper) {
            $add('Temporary helper pages (for the LIVE site: they must not stay online)', $rel, $size);
            continue;
        }
        if (isset($official[$rel]) || in_array($rel, $settings, true) || in_array($rel, $optional, true)) { continue; }
        if (preg_match('/\.example\.php$/', $name)) { continue; }
        if (isset($strayFiles[$rel]) && is_file($root . '/' . $strayFiles[$rel])) {
            $add('Stray copies from earlier copy-and-paste slips', $rel, $size);
            continue;
        }
        $cat = classify($rel);
        if ($cat !== null) { $add($cat, $rel, $size); continue; }
        // not junk and not official: show it for a human to judge, but only in the folders that hold code
        $top = explode('/', $rel)[0];
        $codeFile = (in_array($top, ['classes', 'tools', 'config', 'database'], true) || ($top === 'public' && substr_count($rel, '/') === 1))
            && preg_match('/\.(php|sql|txt)$/i', $name);
        if ($codeFile) { $review[] = $rel; }
    }
    if (!$hasContent && $relDir !== '' && !in_array($relDir, $keepDirs, true) && !in_array(explode('/', $relDir)[0], ['storage', 'backups', 'vendor', '.git'], true)) {
        $emptyDirs[] = $relDir;
    }
}
foreach ($strayFolders as $folder) {
    if (is_dir($root . '/' . $folder)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $rel = norm(substr($f->getPathname(), strlen($root) + 1));
            if ($f->isFile()) { $add('Stray copies from earlier copy-and-paste slips', $rel, (int) $f->getSize()); }
        }
        $emptyDirs[] = $folder;
    }
}
sort($review);

// ------------------------------------------------------------ report
echo "CLEAN UP UNNECESSARY FILES\n" . str_repeat('=', 60) . "\n";
echo "Project folder: $root\n\n";
$totalFiles = 0; $totalBytes = 0;
ksort($found);
foreach ($found as $cat => $list) {
    sort($list);
    $bytes = array_sum(array_column($list, 1));
    echo "$cat: " . count($list) . ' file(s), ' . human($bytes) . "\n";
    foreach (array_slice($list, 0, 25) as [$rel, $size]) { echo '   ' . $rel . '  (' . human($size) . ")\n"; }
    if (count($list) > 25) { echo '   ... and ' . (count($list) - 25) . " more\n"; }
    echo "\n";
    $totalFiles += count($list); $totalBytes += $bytes;
}
if ($emptyDirs) {
    echo 'Empty folders: ' . count($emptyDirs) . "\n";
    foreach ($emptyDirs as $d) { echo '   ' . $d . "/\n"; }
    echo "\n";
}
if ($review) {
    echo "REVIEW (these are NOT in MANIFEST.json, so they are not official project files, but the tool never moves them; look at them yourself):\n";
    foreach ($review as $r) { echo '   ' . $r . "\n"; }
    echo "\n";
}
if ($totalFiles === 0 && !$emptyDirs) {
    echo "Nothing to clean up. The project folder is tidy.\n";
    exit(0);
}
echo "Total that can be cleaned up: $totalFiles file(s), " . human($totalBytes) . (count($emptyDirs) ? ', ' . count($emptyDirs) . ' empty folder(s)' : '') . ".\n";

if (!$doDelete) {
    echo "\nNothing was changed. To clean up, run:  php tools/cleanup_project.php --delete\n";
    echo "(the files are moved to a recovery folder, not destroyed)\n";
    exit(0);
}

// ------------------------------------------------------------ do it
$word = $permanent ? 'PERMANENT' : 'CLEAN';
if (!$yes) {
    echo $permanent
        ? "\nThese files will be DELETED FOR GOOD. Type PERMANENT to continue, anything else cancels: "
        : "\nThe files will be moved to a recovery folder next to the project. Type CLEAN to continue, anything else cancels: ";
    $typed = fgets(STDIN);
    if ($typed === false || trim($typed) !== $word) {
        echo "\nCancelled. Nothing was changed.\n";
        exit(1);
    }
}

$trash = dirname($root) . DIRECTORY_SEPARATOR . basename($root) . '-cleanup-' . date('Ymd-His');
$moved = 0; $failed = [];
foreach ($found as $list) {
    foreach ($list as [$rel]) {
        $src = $root . '/' . $rel;
        if (!is_file($src)) { continue; }
        if ($permanent) {
            if (@unlink($src)) { $moved++; } else { $failed[] = $rel; }
            continue;
        }
        $dest = $trash . '/' . $rel;
        if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0777, true)) { $failed[] = $rel; continue; }
        if (@rename($src, $dest) || (@copy($src, $dest) && @unlink($src))) { $moved++; } else { $failed[] = $rel; }
    }
}
$removedDirs = 0;
foreach (array_reverse($emptyDirs) as $d) {
    if (is_dir($root . '/' . $d) && count(array_diff(scandir($root . '/' . $d), ['.', '..'])) === 0 && @rmdir($root . '/' . $d)) { $removedDirs++; }
}
echo "\nDone. " . ($permanent ? 'Deleted' : 'Moved') . " $moved file(s)" . ($removedDirs ? " and removed $removedDirs empty folder(s)" : '') . ".\n";
if (!$permanent && $moved > 0) {
    echo "Recovery folder: $trash\n";
    echo "To put a file back, copy it from there to the same place in the project. Delete the recovery folder when you are sure.\n";
}
if ($failed) {
    echo "These could not be " . ($permanent ? 'deleted' : 'moved') . " (close any program using them and run again):\n";
    foreach ($failed as $f) { echo '   ' . $f . "\n"; }
    exit(1);
}
echo "Next: php tools/verify_files.php  (confirms every official file is still in place)\n";
exit(0);
