<?php
// Adds the light / dark switch to every page, and makes every page reload the
// stylesheet automatically whenever it changes (so an old, cached look can
// never stick around).
//
// Run it from the project folder:
//     php tools\apply_theme.php           makes the changes
//     php tools\apply_theme.php --check   only reports what it WOULD change
//
// It is safe to run more than once. Before changing any file it saves a copy in
// tools/backups/, and if a changed file would no longer be valid PHP it puts the
// original back.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool can only be run from the command line.');
}

$root      = dirname(__DIR__);
$checkOnly = in_array('--check', $argv, true);

if (!is_file($root . '/public/assets/js/theme.js')) {
    echo "STOPPED: public/assets/js/theme.js is missing. Put that file there first, then run this again.\n";
    exit(1);
}

const CSS_PLAIN     = 'href="assets/css/style.css"';
const CSS_VERSIONED = 'href="assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . \'/assets/css/style.css\') ?>"';
const JS_TAG        = '<script src="assets/js/theme.js?v=<?= (int) @filemtime(__DIR__ . \'/assets/js/theme.js\') ?>"></script>';

// Is this text valid PHP?
function isValidPhp(string $code): bool
{
    $tmp = tempnam(sys_get_temp_dir(), 'egs');
    $php = $tmp . '.php';
    file_put_contents($php, $code);
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($php) . ' 2>&1', $out, $status);
    @unlink($php);
    @unlink($tmp);
    return $status === 0;
}

// The file's path relative to the project folder, always with forward slashes. On Windows the
// project folder is written with backslashes (C:\xampp\...) while glob() may return a mixture,
// so both are made the same before the project folder is cut off.
function shortPath(string $root, string $file): string
{
    $r = rtrim(str_replace('\\', '/', $root), '/');
    $f = str_replace('\\', '/', $file);
    if (stripos($f, $r . '/') === 0) {
        return substr($f, strlen($r) + 1);
    }
    return ltrim($f, '/');
}

// The changes for an ordinary page. Returns [new text, list of what changed].
function patchPage(string $src): array
{
    $changes = [];
    $new     = $src;

    // 1. the switch: a script tag straight after the stylesheet link
    if (strpos($new, 'assets/js/theme.js') === false
        && preg_match('/<link rel="stylesheet" href="assets\/css\/style\.css[^"]*">/', $new, $m, PREG_OFFSET_CAPTURE)) {
        $at  = $m[0][1] + strlen($m[0][0]);
        $new = substr($new, 0, $at) . "\n    " . JS_TAG . substr($new, $at);
        $changes[] = 'light/dark switch';
    }

    // 2. the stylesheet link gets an automatic version number
    if (strpos($new, CSS_PLAIN) !== false) {
        $new = str_replace(CSS_PLAIN, CSS_VERSIONED, $new);
        $changes[] = 'stylesheet auto-reload';
    }
    return [$new, $changes];
}

// The "no access" page that lives inside classes/Auth.php (the HTML is in a PHP string).
function patchAuth(string $src): array
{
    $old = '<link rel="stylesheet" href="assets/css/style.css"></head><body>\'';
    $new = '<link rel="stylesheet" href="assets/css/style.css?v=\' . (int) @filemtime(__DIR__ . \'/../public/assets/css/style.css\') . \'">'
         . '<script src="assets/js/theme.js"></script></head><body>\'';
    if (strpos($src, $old) === false) {
        return [$src, []];
    }
    return [str_replace($old, $new, $src), ['light/dark switch', 'stylesheet auto-reload']];
}

$targets = [];
foreach (glob($root . '/public/*.php') as $file) {
    $targets[$file] = 'page';
}
$targets[$root . '/classes/Auth.php'] = 'auth';

$stamp     = date('Ymd-His');
$backupDir = $root . '/tools/backups/' . $stamp;
$changed   = 0;
$already   = 0;
$skipped   = 0;
$failed    = 0;

echo ($checkOnly ? "CHECK ONLY (nothing will be changed)\n" : "Applying the light/dark switch\n") . str_repeat('-', 40) . "\n";

foreach ($targets as $file => $kind) {
    $short = shortPath($root, $file);
    $src   = (string) file_get_contents($file);

    if ($kind === 'page' && strpos($src, 'assets/css/style.css') === false) {
        echo "skip      $short   (not a page with a layout)\n";
        $skipped++;
        continue;
    }

    [$new, $changes] = ($kind === 'auth') ? patchAuth($src) : patchPage($src);

    if (count($changes) === 0) {
        echo "ok        $short   (already up to date)\n";
        $already++;
        continue;
    }

    if ($checkOnly) {
        echo "WOULD     $short   (" . implode(', ', $changes) . ")\n";
        $changed++;
        continue;
    }

    if (!isValidPhp($new)) {
        echo "NOT DONE  $short   the edit would break this file, so it was left exactly as it was\n";
        $failed++;
        continue;
    }

    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0777, true);
    }
    copy($file, $backupDir . '/' . str_replace('/', '_', $short) . '.txt');
    file_put_contents($file, $new);
    echo "CHANGED   $short   (" . implode(', ', $changes) . ")\n";
    $changed++;
}

echo str_repeat('-', 40) . "\n";
echo ($checkOnly ? "would change: $changed" : "changed: $changed") . " | already done: $already | skipped: $skipped" . ($failed ? " | NOT DONE: $failed" : '') . "\n";
if (!$checkOnly && $changed > 0) {
    echo "Copies of the original files are in tools/backups/$stamp/\n";
}
exit($failed > 0 ? 1 : 0);
