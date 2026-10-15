<?php
require_once __DIR__ . '/../config/report_helpers.php';

// The list of reports. Each one covers a period you choose, prints cleanly on A4 and downloads as a CSV file for your own spreadsheet.
$user = Auth::requireRole('admin');

$reports = [
    ['admin_report_register.php',      'Bookings register',          'Every appointment in a period, for the whole salon or one stylist, with its status and price. Search by client or reference.'],
    ['admin_report_takings.php',       'Takings',                    'The value of completed appointments, day by day and service by service: the figure the till should agree with.'],
    ['admin_report_stylists.php',      'Stylist activity',           'Hours each stylist was rostered and booked for, with the appointments they completed and lost to cancellation.'],
    ['admin_report_cancellations.php', 'Cancellations and no-shows', 'Who cancelled what, when, and how much notice the salon was given, and which clients did not arrive.'],
    ['admin_report_customer.php',      'Customer history',           "One client's complete record: every visit, what it cost, and their loyalty balance."],
    ['admin_report_overview.php',      'Busy periods and popular services', 'Bookings by day of the week and by hour, the most booked services, and a summary of the period.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reports - Executive Grooming Studio</title>
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

        <p class="meta">ADMINISTRATION</p>
        <h2>Reports</h2>
        <p>Each report covers a period you choose, prints cleanly on A4, and downloads as a CSV file for your own spreadsheet.
            Money is always the price the client was quoted when they booked, so changing a price in the catalogue never changes a past report.</p>

        <div class="report-cards">
            <?php foreach ($reports as [$url, $name, $text]): ?>
                <section class="service-card report-card">
                    <h3><?= e($name) ?></h3>
                    <p><?= e($text) ?></p>
                    <a class="button" href="<?= e($url) ?>">Open</a>
                </section>
            <?php endforeach; ?>
        </div>
        <p class="meta">Allergy and sensitivity notes never appear in a report or a download. They are health information, shown to the stylist only, and a report is something that gets printed and shared.</p>
    </main>
</body>
</html>
