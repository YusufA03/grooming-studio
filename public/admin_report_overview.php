<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/admin_nav.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Report.php';

// The administrator's reports: busy periods, popular services and stylist performance
// (Phase 1 Section 11, Phase 3 Section 3.5). Each figure counts the bookings that START in the
// chosen period. Nothing on this page shows a client's personal details.

// Only a logged-in administrator may open this page.
$user = Auth::requireRole('admin');

$today = new DateTimeImmutable('today');
$to    = $today;
$from  = $today->modify('-29 days');   // the default: the last 30 days
$problem = null;

// Reads a day typed into the filter. Returns null if it is not a real date.
function readDay(string $text): ?DateTimeImmutable
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
    return ($parsed !== false && $parsed->format('Y-m-d') === $text) ? $parsed : null;
}

if (isset($_GET['from']) || isset($_GET['to'])) {
    $f = readDay(inputText($_GET, 'from', ''));
    $t = readDay(inputText($_GET, 'to', ''));
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

$summary = $weekdays = $hours = $services = $stylists = null;
$loadError = null;
try {
    $summary  = Report::summary($from, $to);
    $weekdays = Report::byWeekday($from, $to);
    $hours    = Report::byHour($from, $to);
    $services = Report::byService($from, $to);
    $stylists = Report::byStylist($from, $to);
} catch (PDOException $ex) {
    error_log('Could not build the report: ' . $ex->getMessage());
    $loadError = 'Sorry, something went wrong on our side. Please try again later.';
}

// ---- Download the figures as a spreadsheet (CSV) ----
if ($loadError === null && inputText($_GET, 'export', '') === 'csv') {
    // A cell that starts with = + - or @ could be run as a formula by a spreadsheet, so it is defused.
    $cell = static function ($value): string {
        $text = (string) $value;
        return ($text !== '' && strpbrk($text[0], "=+-@\t\r") !== false) ? "'" . $text : $text;
    };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="grooming-studio-report-' . $from->format('Y-m-d') . '-to-' . $to->format('Y-m-d') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // lets Excel read it as UTF-8
    fputcsv($out, ['Executive Grooming Studio report', $from->format('Y-m-d'), $to->format('Y-m-d')]);
    fputcsv($out, []);
    fputcsv($out, ['SUMMARY']);
    foreach ([
        'Bookings' => $summary['total'], 'Upcoming (confirmed)' => $summary['confirmed'], 'Completed' => $summary['completed'],
        'No-shows' => $summary['no_show'], 'Cancelled' => $summary['cancelled'],
        'Revenue from completed (R)' => number_format($summary['revenue'], 2, '.', ''),
        'No-show rate' => $summary['no_show_rate'] === null ? '' : round($summary['no_show_rate'] * 100, 1) . '%',
        'Cancellation rate' => $summary['cancellation_rate'] === null ? '' : round($summary['cancellation_rate'] * 100, 1) . '%',
    ] as $label => $value) {
        fputcsv($out, [$label, $value]);
    }
    fputcsv($out, []);
    fputcsv($out, ['BUSY DAYS (bookings that were not cancelled)']);
    foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $i => $dayName) {
        fputcsv($out, [$dayName, $weekdays[$i]]);
    }
    fputcsv($out, []);
    fputcsv($out, ['BUSY HOURS (start hour, bookings)']);
    foreach ($hours as $h => $n) {
        fputcsv($out, [sprintf('%02d:00', $h), $n]);
    }
    fputcsv($out, []);
    fputcsv($out, ['SERVICES', 'Bookings', 'Completed', 'Revenue (R)']);
    foreach ($services as $s) {
        fputcsv($out, [$cell($s['name']), $s['bookings'], $s['completed'], number_format((float) $s['revenue'], 2, '.', '')]);
    }
    fputcsv($out, []);
    fputcsv($out, ['STYLISTS', 'Completed', 'No-shows', 'Cancelled', 'Upcoming', 'Hours booked', 'Revenue (R)']);
    foreach ($stylists as $s) {
        fputcsv($out, [$cell($s['name']), $s['completed'], $s['no_show'], $s['cancelled'], $s['confirmed'],
            round(((int) $s['booked_minutes']) / 60, 1), number_format((float) $s['revenue'], 2, '.', '')]);
    }
    fclose($out);
    exit;
}

$range = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
$presets = [
    'Last 7 days'   => ['from' => $today->modify('-6 days')->format('Y-m-d'),  'to' => $today->format('Y-m-d')],
    'Last 30 days'  => ['from' => $today->modify('-29 days')->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
    'This month'    => ['from' => $today->modify('first day of this month')->format('Y-m-d'), 'to' => $today->modify('last day of this month')->format('Y-m-d')],
    'Next 7 days'   => ['from' => $today->format('Y-m-d'), 'to' => $today->modify('+6 days')->format('Y-m-d')],
];
$percent = static fn (?float $r): string => $r === null ? 'n/a' : number_format($r * 100, 1) . '%';
$rand    = static fn ($n): string => 'R' . number_format((float) $n, 2);
$dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$maxDay   = $weekdays ? max(1, max($weekdays)) : 1;
$maxHour  = $hours ? max(1, max($hours)) : 1;
$maxSvc   = $services ? max(1, max(array_map(static fn ($s) => (int) $s['bookings'], $services))) : 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Busy periods and popular services - Executive Grooming Studio</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <script src="assets/js/theme.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
</head>
<body>
    <header class="site-header">
        <h1>Executive Grooming Studio</h1>
        <p>Administrator Dashboard</p>
    </header>

    <main class="container">
        <div class="topbar">
            <span>Logged in as <strong><?= e($user['name']) ?></strong> (administrator)</span>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <button type="submit" class="button">Log out</button>
            </form>
        </div>

        <?= adminNav('reports') ?>

        <p class="no-print"><a href="admin_reports.php">&larr; All reports</a></p>
        <h2>Busy periods and popular services</h2>

        <form method="get" action="admin_report_overview.php" class="filter-form">
            <label for="from">From</label>
            <input type="date" name="from" id="from" value="<?= e($range['from']) ?>" required>
            <label for="to">To</label>
            <input type="date" name="to" id="to" value="<?= e($range['to']) ?>" required>
            <button type="submit" class="button">Show report</button>
        </form>
        <p class="report-tools">
            <?php foreach ($presets as $label => $p): ?>
                <a class="button" href="admin_report_overview.php?<?= e(http_build_query($p)) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
            <a class="button" href="admin_report_overview.php?<?= e(http_build_query($range + ['export' => 'csv'])) ?>">Download as spreadsheet (CSV)</a>
        </p>

        <?php if ($problem): ?><p class="error"><?= e($problem) ?> Showing the last 30 days instead.</p><?php endif; ?>
        <?php if ($loadError): ?><p class="error"><?= e($loadError) ?></p><?php else: ?>

        <p class="meta">Period: <?= e($from->format('j F Y')) ?> to <?= e($to->format('j F Y')) ?>. Each figure counts the bookings that start in this period.</p>

        <div class="kpi-grid">
            <div class="kpi"><span class="kpi-value"><?= (int) $summary['total'] ?></span><span class="kpi-label">Bookings (all statuses)</span></div>
            <div class="kpi"><span class="kpi-value"><?= (int) $summary['completed'] ?></span><span class="kpi-label">Completed</span></div>
            <div class="kpi"><span class="kpi-value"><?= (int) $summary['confirmed'] ?></span><span class="kpi-label">Upcoming (confirmed)</span></div>
            <div class="kpi"><span class="kpi-value"><?= $percent($summary['no_show_rate']) ?></span><span class="kpi-label">No-show rate (<?= (int) $summary['no_show'] ?> no-shows)</span></div>
            <div class="kpi"><span class="kpi-value"><?= $percent($summary['cancellation_rate']) ?></span><span class="kpi-label">Cancellation rate (<?= (int) $summary['cancelled'] ?> cancelled)</span></div>
            <div class="kpi"><span class="kpi-value"><?= e($rand($summary['revenue'])) ?></span><span class="kpi-label">Revenue from completed</span></div>
        </div>

        <h3>Busy periods</h3>
        <?php if ($summary['total'] - $summary['cancelled'] === 0): ?>
            <p>There are no bookings in this period.</p>
        <?php else: ?>
            <h4>By day of the week</h4>
            <?php foreach ($dayNames as $i => $dayName): ?>
                <div class="chart-row">
                    <span><?= e($dayName) ?></span>
                    <span class="bar"><span style="width: <?= (int) round($weekdays[$i] / $maxDay * 100) ?>%"></span></span>
                    <span><?= (int) $weekdays[$i] ?></span>
                </div>
            <?php endforeach; ?>
            <h4>By start time</h4>
            <?php foreach ($hours as $h => $n): ?>
                <div class="chart-row">
                    <span><?= e(sprintf('%02d:00', $h)) ?></span>
                    <span class="bar"><span style="width: <?= (int) round($n / $maxHour * 100) ?>%"></span></span>
                    <span><?= (int) $n ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <h3>Popular services</h3>
        <table class="schedule-table">
            <thead><tr><th>Service</th><th>Bookings</th><th class="bar-cell"></th><th>Completed</th><th>Revenue</th></tr></thead>
            <tbody>
                <?php foreach ($services as $s): ?>
                    <tr>
                        <td><?= e($s['name']) ?></td>
                        <td><?= (int) $s['bookings'] ?></td>
                        <td class="bar-cell"><span class="bar"><span style="width: <?= (int) round($s['bookings'] / $maxSvc * 100) ?>%"></span></span></td>
                        <td><?= (int) $s['completed'] ?></td>
                        <td><?= e($rand($s['revenue'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3>Stylist performance</h3>
        <table class="schedule-table">
            <thead><tr><th>Stylist</th><th>Completed</th><th>No-shows</th><th>Cancelled</th><th>Upcoming</th><th>Hours booked</th><th>Revenue</th></tr></thead>
            <tbody>
                <?php foreach ($stylists as $s): ?>
                    <tr>
                        <td><?= e($s['name']) ?></td>
                        <td><?= (int) $s['completed'] ?></td>
                        <td><?= (int) $s['no_show'] ?></td>
                        <td><?= (int) $s['cancelled'] ?></td>
                        <td><?= (int) $s['confirmed'] ?></td>
                        <td><?= e(number_format(((int) $s['booked_minutes']) / 60, 1)) ?></td>
                        <td><?= e($rand($s['revenue'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="meta">How the figures are worked out. Busy periods and popular services leave out cancelled bookings. The no-show rate is no-shows divided by
            completed plus no-shows. The cancellation rate is cancelled bookings divided by all bookings. Revenue counts completed appointments only, at the price charged when
            they were booked. Hours booked add up the length of confirmed and completed appointments.</p>
        <?php endif; ?>
    </main>
</body>
</html>
