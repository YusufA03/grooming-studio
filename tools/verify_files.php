<?php
// Checks that every file in this project is the official, latest version.
// It READS only; it never changes anything.
//
// Run it from the project folder:   php tools\verify_files.php
//
// It compares each file with the fingerprint in MANIFEST.json (which comes in the
// bundle). Differences in line endings (Windows or Linux) are ignored.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool can only be run from the command line.');
}

$root     = dirname(__DIR__);
$manifest = $root . '/MANIFEST.json';
if (!is_file($manifest)) {
    echo "STOPPED: MANIFEST.json is not in the project folder. Unzip the latest bundle over the project first.\n";
    exit(1);
}
$data = json_decode((string) file_get_contents($manifest), true);
if (!is_array($data) || !isset($data['files'])) {
    echo "STOPPED: MANIFEST.json is damaged. Download the bundle again.\n";
    exit(1);
}

$optional  = $data['optional'] ?? [];
$matching  = 0;
$different = [];
$missing   = [];

foreach ($data['files'] as $path => $expected) {
    $full = $root . '/' . $path;
    if (!is_file($full)) {
        if (!in_array($path, $optional, true)) {
            $missing[] = $path;
        }
        continue;
    }
    $actual = hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($full)));
    if (hash_equals($expected, $actual)) {
        $matching++;
    } else {
        $different[] = $path;
    }
}

echo "Bundle version: " . ($data['version'] ?? '?') . "\n";
echo "Files that match the official version: $matching of " . count($data['files']) . "\n";
if (count($different) === 0 && count($missing) === 0) {
    echo "\nRESULT: every file is the official, latest version.\n";
    exit(0);
}
if ($different) {
    echo "\nDIFFERENT (replace each with the file from the bundle; if you changed one on purpose, ignore it):\n";
    foreach ($different as $p) { echo "  - $p\n"; }
}
if ($missing) {
    echo "\nMISSING (copy these from the bundle):\n";
    foreach ($missing as $p) { echo "  - $p\n"; }
}
echo "\nRESULT: " . (count($different) + count($missing)) . " file(s) need attention.\n";
exit(1);
