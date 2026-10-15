<?php
// Updates your project to the latest version in ONE command, so nothing has to be copied by hand.
//
//   C:\xampp\php\php.exe install_update.php "C:\xampp\htdocs\grooming-studio" --check     (only shows what it WOULD do)
//   C:\xampp\php\php.exe install_update.php "C:\xampp\htdocs\grooming-studio"             (does it)
//
// Run it from the folder you extracted the zip into.
//
// WHAT IT DOES
//   - copies every official file into the right place (only the ones that are new or different)
//   - first saves a copy of every file it replaces or removes, in a folder NEXT TO your project
//     (so you can always go back)
//   - removes known stray files from earlier copy-and-paste slips
//   - adds the missing lines to .gitignore
//   - checks every file afterwards and tests that every PHP file is valid
// WHAT IT NEVER TOUCHES
//   config\db_config.php, config\mail_config.php, config\cron_config.php, the vendor folder,
//   the .git folder, uploaded service pictures, your hero photo, composer files.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool can only be run from the command line.');
}

$source = __DIR__;
$args   = array_slice($argv, 1);
$check  = in_array('--check', $args, true);
$args   = array_values(array_filter($args, fn ($a) => $a !== '--check'));

function say(string $line = ''): void { echo $line . "\n"; }
function stop(string $message): void { say("STOPPED: $message"); exit(1); }
function normalized(string $path): string { return hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($path))); }

if (count($args) !== 1) {
    stop("Tell me which folder to update, for example:\n  C:\\xampp\\php\\php.exe install_update.php \"C:\\xampp\\htdocs\\grooming-studio\"");
}
$target = realpath(rtrim($args[0], "\\/ \t\"'"));
if ($target === false || !is_dir($target)) {
    stop('that folder does not exist: ' . $args[0]);
}
if (realpath($source) === $target) {
    stop('that is the folder the update was extracted into. Give me YOUR project folder instead.');
}
if (!is_file($target . '/classes/Booking.php') || !is_file($target . '/public/book.php')) {
    stop("that does not look like the grooming-studio project (classes\\Booking.php and public\\book.php were not found there).");
}
$manifestFile = $source . '/MANIFEST.json';
if (!is_file($manifestFile)) {
    stop('MANIFEST.json is missing from the extracted update. Extract the whole zip again.');
}
$manifest = json_decode((string) file_get_contents($manifestFile), true);
if (!is_array($manifest) || !isset($manifest['files'])) {
    stop('MANIFEST.json is damaged. Download the zip again.');
}
// The update must be complete and unaltered before we use it.
foreach ($manifest['files'] as $path => $hash) {
    if (!is_file($source . '/' . $path) || normalized($source . '/' . $path) !== $hash) {
        stop("the extracted update is incomplete or damaged ($path). Extract the zip again, all of it.");
    }
}

// Files that are never touched, even if the update contained them.
$protected = ['config/db_config.php', 'config/mail_config.php', 'config/cron_config.php'];

// Stray copies left by earlier slips: removed only if the real file exists where it belongs.
$strayFiles = [
    'public/Service.php'                => 'classes/Service.php',
    'public/assets/stylist_schedule.php' => 'public/stylist_schedule.php',
];
$strayFolders = ['tools/backups'];   // broken backups made by an earlier version of the theme tool on Windows

// ---- plan -----------------------------------------------------------------------------
$toAdd = $toUpdate = $same = [];
foreach ($manifest['files'] as $path => $hash) {
    if (in_array($path, $protected, true)) { continue; }
    $dest = $target . '/' . $path;
    if (!is_file($dest))                      { $toAdd[]    = $path; }
    elseif (normalized($dest) !== $hash)      { $toUpdate[] = $path; }
    else                                      { $same[]     = $path; }
}
$toAdd[]    = 'MANIFEST.json';
$removeFiles = [];
foreach ($strayFiles as $stray => $real) {
    if (is_file($target . '/' . $stray) && is_file($target . '/' . $real) || is_file($target . '/' . $stray) && isset($manifest['files'][$real])) {
        $removeFiles[] = $stray;
    }
}
$removeFolders = array_values(array_filter($strayFolders, fn ($f) => is_dir($target . '/' . $f)));

$gitignoreWanted = ['storage/inspiration/*', '!storage/inspiration/.htaccess', 'backups/', 'tools/backups/', 'test-results/'];
$gitignorePath   = $target . '/.gitignore';
$gitignoreText   = is_file($gitignorePath) ? (string) file_get_contents($gitignorePath) : '';
$gitignoreLines  = preg_split('/\r\n|\r|\n/', $gitignoreText);
$gitignoreAdd    = array_values(array_filter($gitignoreWanted, fn ($l) => !in_array($l, $gitignoreLines, true)));

say(($check ? 'CHECK ONLY: nothing will be changed' : 'Updating') . "\nproject: $target\nupdate : " . ($manifest['version'] ?? '?'));
say(str_repeat('-', 60));
say('files already up to date : ' . count($same));
say('files that will be UPDATED: ' . count($toUpdate));
foreach ($toUpdate as $p) { say("    update  $p"); }
say('files that will be ADDED   : ' . count($toAdd));
foreach ($toAdd as $p) { say("    add     $p"); }
say('stray files to be removed  : ' . (count($removeFiles) + count($removeFolders)));
foreach ($removeFiles as $p)   { say("    remove  $p"); }
foreach ($removeFolders as $p) { say("    remove  $p/  (folder)"); }
say('.gitignore lines to add    : ' . count($gitignoreAdd) . ($gitignoreAdd ? '  (' . implode('  ', $gitignoreAdd) . ')' : ''));
say(str_repeat('-', 60));

if ($check) {
    say('Nothing was changed. Run it again without --check to apply.');
    exit(0);
}
if (!$toUpdate && count($toAdd) === 1 && !$removeFiles && !$removeFolders && !$gitignoreAdd) {
    say('Your project is already up to date. Nothing to do.');
    exit(0);
}

// ---- backup ---------------------------------------------------------------------------
$backup = dirname($target) . '/' . basename($target) . '-update-backup-' . date('Ymd-His');
function saveCopy(string $target, string $backup, string $relative): void
{
    $from = $target . '/' . $relative;
    $to   = $backup . '/' . $relative;
    if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0777, true) && !is_dir(dirname($to))) {
        stop('could not create the backup folder ' . dirname($to));
    }
    if (!copy($from, $to)) {
        stop("could not save a backup copy of $relative, so nothing was changed there");
    }
}
$saved = 0;
foreach (array_merge($toUpdate, $removeFiles, ['.gitignore']) as $p) {
    if (is_file($target . '/' . $p)) { saveCopy($target, $backup, $p); $saved++; }
}
if (is_file($target . '/MANIFEST.json')) { saveCopy($target, $backup, 'MANIFEST.json'); $saved++; }
foreach ($removeFolders as $folder) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target . '/' . $folder, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) { saveCopy($target, $backup, str_replace('\\', '/', substr($f->getPathname(), strlen($target) + 1))); $saved++; }
    }
}

// ---- apply ----------------------------------------------------------------------------
$failed = [];
foreach (array_merge($toUpdate, $toAdd) as $p) {
    $from = $source . '/' . $p;
    $dest = $target . '/' . $p;
    if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0777, true) && !is_dir(dirname($dest))) { $failed[] = $p; continue; }
    if (!copy($from, $dest) || normalized($dest) !== normalized($from)) { $failed[] = $p; }
}
foreach ($removeFiles as $p) { @unlink($target . '/' . $p); }
foreach ($removeFolders as $folder) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target . '/' . $folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($target . '/' . $folder);
}
if ($gitignoreAdd) {
    $eol  = (strpos($gitignoreText, "\r\n") !== false) ? "\r\n" : "\n";
    $text = $gitignoreText;
    if ($text !== '' && substr($text, -1) !== "\n") { $text .= $eol; }
    $text .= '# added by the update' . $eol . implode($eol, $gitignoreAdd) . $eol;
    file_put_contents($gitignorePath, $text);
}
if ($failed) {
    say('PROBLEM: these files could not be copied correctly: ' . implode(', ', $failed));
    say("Your originals are safe in: $backup");
    exit(1);
}

// ---- check ----------------------------------------------------------------------------
$bad = 0;
foreach ($manifest['files'] as $path => $hash) {
    if (in_array($path, $protected, true)) { continue; }
    if (!is_file($target . '/' . $path) || normalized($target . '/' . $path) !== $hash) { $bad++; say("  does not match: $path"); }
}
$syntax = 0;
foreach (array_merge($toUpdate, $toAdd) as $p) {
    if (substr($p, -4) === '.php') {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($target . '/' . $p) . ' 2>&1', $out, $rc);
        if ($rc !== 0) { $syntax++; say("  PHP problem in $p: " . implode(' ', $out)); }
    }
}
say('updated ' . count($toUpdate) . ' | added ' . (count($toAdd) - 1) . ' | removed ' . (count($removeFiles) + count($removeFolders)) . ' stray | .gitignore lines added ' . count($gitignoreAdd));
say("a copy of everything that was replaced or removed ($saved files) is in:\n  $backup");
if ($bad === 0 && $syntax === 0) {
    say("\nRESULT: your project is now exactly the latest version, and every file checks out.");
    say("Your settings (config\\db_config.php, mail_config.php, cron_config.php), vendor, .git and uploads were not touched.");
    exit(0);
}
say("\nRESULT: $bad file(s) do not match and $syntax have PHP problems. Your originals are in the backup folder above.");
exit(1);
