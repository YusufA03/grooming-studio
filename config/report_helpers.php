<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/admin_nav.php';
require_once __DIR__ . '/../classes/Auth.php';

// Shared pieces of the five management reports: choosing a period, the page layout, printing on A4 and the CSV download.

// Reads ?from=...&to=... Returns [from, to, problem]. The default is the last 30 days. A bad period shows a message and uses the default.
function readPeriod(): array
{
    $today   = new DateTimeImmutable('today');
    $from    = $today->modify('-29 days');
    $to      = $today;
    $problem = null;
    $day = static function (string $text): ?DateTimeImmutable {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        return ($parsed !== false && $parsed->format('Y-m-d') === $text) ? $parsed : null;
    };
    if (isset($_GET['from']) || isset($_GET['to'])) {
        $f = $day(inputText($_GET, 'from', ''));
        $t = $day(inputText($_GET, 'to', ''));
        if ($f === null || $t === null) {
            $problem = 'Please choose real dates for both the first and the last day.';
        } elseif ($f > $t) {
            $problem = 'The first day must not be after the last day.';
        } elseif ($f->diff($t)->days > 365) {
            $problem = 'Please choose a period of one year or less.';
        } else {
            $from = $f;
            $to   = $t;
        }
    }
    return [$from, $to, $problem];
}

function money($n): string
{
    return 'R' . number_format((float) $n, 2);
}

// "3 days 4 h", "5 h 20 min", "12 min" from a number of minutes (negative means after the appointment began).
function noticeText(?int $minutes): string
{
    if ($minutes === null) { return 'not recorded'; }
    if ($minutes < 0)      { return 'after it started'; }
    $d = intdiv($minutes, 1440);
    $h = intdiv($minutes % 1440, 60);
    $m = $minutes % 60;
    if ($d > 0) { return $d . ' day' . ($d === 1 ? '' : 's') . ($h > 0 ? ' ' . $h . ' h' : ''); }
    if ($h > 0) { return $h . ' h' . ($m > 0 ? ' ' . $m . ' min' : ''); }
    return $m . ' min';
}

// A cell that starts with = + - @ could be run as a formula by a spreadsheet, so it is defused.
function csvCell($value): string
{
    $text = (string) $value;
    // A real number such as -100 is left alone. Only TEXT that begins like a formula is defused.
    if ($text === '' || is_int($value) || is_float($value) || preg_match('/^[+-]?\d+(\.\d+)?$/', $text)) {
        return $text;
    }
    return (strpbrk($text[0], "=+-@\t\r") !== false) ? "'" . $text : $text;
}

// Sends a CSV file and stops. $rows is a list of rows, each a list of cells.
function sendCsv(string $filename, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // lets Excel read it as UTF-8
    foreach ($rows as $row) {
        fputcsv($out, array_map('csvCell', $row));
    }
    fclose($out);
    exit;
}

// The top of a report page: the head, the salon header, the administrator bar and menu, and the title. Also a block that only appears
// when printing (salon name, report title, period, who and when), because the menu and buttons are hidden on paper.
function reportOpen(array $user, string $title, string $period = ''): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - Executive Grooming Studio</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="site-header">
        <h1>Executive Grooming Studio</h1>
        <p>Administrator Dashboard</p>
    </header>

    <main class="container report-page">
        <div class="topbar">
            <span>Logged in as <strong><?= e($user['name']) ?></strong> (administrator)</span>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <button type="submit" class="button">Log out</button>
            </form>
        </div>
        <?= adminNav('reports') ?>

        <div class="print-only report-letterhead">
            <strong>Executive Grooming Studio</strong>
            <span><?= e($title) ?><?= $period !== '' ? ' &middot; ' . e($period) : '' ?></span>
            <span>Printed <?= e((new DateTimeImmutable('now'))->format('j F Y, H:i')) ?> by <?= e($user['name']) ?></span>
        </div>
        <p class="no-print"><a href="admin_reports.php">&larr; All reports</a></p>
        <h2><?= e($title) ?></h2>
<?php
}

function reportClose(): void
{
    ?>
        <p class="meta report-footnote">Allergy and sensitivity notes never appear in a report or a download: they are health information,
            shown to the stylist only, and a report is something that gets printed and shared. Money is always the price the client was quoted when they booked.</p>
    </main>
</body>
</html>
<?php
}

// The period chooser with quick buttons, plus Print and CSV buttons. $extra are other values (for example a stylist) kept in the CSV link.
function reportToolbar(DateTimeImmutable $from, DateTimeImmutable $to, ?string $problem, array $extra = [], string $filterHtml = ''): void
{
    $today   = new DateTimeImmutable('today');
    $range   = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
    $presets = [
        'Last 7 days'  => ['from' => $today->modify('-6 days')->format('Y-m-d'),  'to' => $today->format('Y-m-d')],
        'Last 30 days' => ['from' => $today->modify('-29 days')->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
        'This month'   => ['from' => $today->modify('first day of this month')->format('Y-m-d'), 'to' => $today->modify('last day of this month')->format('Y-m-d')],
        'Last month'   => ['from' => $today->modify('first day of last month')->format('Y-m-d'), 'to' => $today->modify('last day of last month')->format('Y-m-d')],
    ];
    ?>
        <form method="get" class="filter-form no-print">
            <label for="from">From</label>
            <input type="date" name="from" id="from" value="<?= e($range['from']) ?>" required>
            <label for="to">To</label>
            <input type="date" name="to" id="to" value="<?= e($range['to']) ?>" required>
            <?= $filterHtml ?>
            <button type="submit" class="button">Show report</button>
        </form>
        <p class="report-tools no-print">
            <?php foreach ($presets as $label => $p): ?>
                <a class="button" href="?<?= e(http_build_query($p + $extra)) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
            <button type="button" class="button" onclick="window.print()">Print (A4)</button>
            <a class="button" href="?<?= e(http_build_query($range + $extra + ['export' => 'csv'])) ?>">Download CSV</a>
        </p>
        <?php if ($problem): ?><p class="error"><?= e($problem) ?> Showing the last 30 days instead.</p><?php endif; ?>
        <p class="meta">Period: <?= e($from->format('j F Y')) ?> to <?= e($to->format('j F Y')) ?>.</p>
<?php
}
