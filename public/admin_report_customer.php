<?php
require_once __DIR__ . '/../config/report_helpers.php';
require_once __DIR__ . '/../classes/Report.php';

// REPORT 5: Customer history. One client's complete record: every visit, what it cost, and their loyalty balance.
$user = Auth::requireRole('admin');

$clientId = filter_var($_GET['client_id'] ?? null, FILTER_VALIDATE_INT);
$search   = mb_substr(trim(inputText($_GET, 'q', '')), 0, 100);
$history  = null;
$clients  = [];
$notFound = false;
$loadError = null;
try {
    if (isset($_GET['client_id'])) {
        $history  = ($clientId !== false && $clientId > 0) ? Report::customerHistory((int) $clientId) : null;
        $notFound = ($history === null);
        if ($notFound) { http_response_code(404); }
    } else {
        $clients = Loyalty::searchClients($search, 50);
    }
} catch (PDOException $ex) {
    error_log('Customer history failed: ' . $ex->getMessage());
    $loadError = 'Sorry, something went wrong on our side. Please try again later.';
}

if ($history !== null && inputText($_GET, 'export', '') === 'csv') {
    $c = $history['client']; $t = $history['totals'];
    $out = [['Customer history'], ['Client', $c['name']], ['Email', (string) ($c['email'] ?? '')], [],
            ['Appointments', $t['bookings']], ['Completed', $t['completed']], ['Total spent (R)', number_format((float) $t['spent'], 2, '.', '')], ['No-shows', $t['no_show']], ['Cancelled', $t['cancelled']],
            ['Upcoming', $t['upcoming']], ['Loyalty balance (points)', $history['balance']], [], ['VISITS (latest 50)'],
            ['Reference', 'Date', 'Start', 'Service', 'Stylist', 'Status', 'Price (R)']];
    foreach ($history['visits'] as $v) { $out[] = [bookingReference((int) $v['booking_id']), substr($v['start_time'], 0, 10), substr($v['start_time'], 11, 5), $v['service_name'], $v['stylist_name'], $v['status'], number_format((float) $v['price_charged'], 2, '.', '')]; }
    $out[] = []; $out[] = ['LOYALTY POINTS']; $out[] = ['When', 'Points', 'Type', 'Note'];
    foreach ($history['ledger'] as $l) { $out[] = [substr($l['created_at'], 0, 16), $l['points'], $l['reason'], $l['note'] ?? '']; }
    sendCsv('customer-history-' . (int) $c['client_id'] . '.csv', $out);
}

reportOpen($user, 'Customer history', $history !== null ? $history['client']['name'] : '');
?>
        <?php if ($loadError): ?><p class="error"><?= e($loadError) ?></p>
        <?php elseif ($notFound): ?><p class="error">We could not find that client.</p><p><a href="admin_report_customer.php">Search for a client</a></p>
        <?php elseif ($history === null): ?>
            <form method="get" class="filter-form no-print">
                <label for="q">Find a client</label>
                <input type="text" name="q" id="q" value="<?= e($search) ?>" maxlength="100" placeholder="Name, email or phone">
                <button type="submit" class="button">Search</button>
            </form>
            <?php if (count($clients) === 0): ?><p>No clients found.</p><?php else: ?>
                <table class="schedule-table report-table">
                    <thead><tr><th>Client</th><th>Contact</th><th class="num">Completed visits</th><th class="num">Points</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($clients as $c): ?>
                            <tr>
                                <td><?= e($c['name']) ?></td>
                                <td><?= ($c['email'] !== null && $c['email'] !== '') ? e($c['email']) : '(no email)' ?></td>
                                <td class="num"><?= (int) $c['visits'] ?></td>
                                <td class="num"><?= (int) $c['balance'] ?></td>
                                <td><a class="button" href="admin_report_customer.php?client_id=<?= (int) $c['client_id'] ?>">Open</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php else: ?>
            <?php $c = $history['client']; $t = $history['totals']; ?>
            <p class="report-tools no-print">
                <button type="button" class="button" onclick="window.print()">Print (A4)</button>
                <a class="button" href="?<?= e(http_build_query(['client_id' => (int) $c['client_id'], 'export' => 'csv'])) ?>">Download CSV</a>
                <a class="button" href="admin_report_customer.php">Another client</a>
            </p>
            <p><strong><?= e($c['name']) ?></strong> &middot; <?= ($c['email'] !== null && $c['email'] !== '') ? e($c['email']) : 'no email' ?></p>
            <div class="kpi-grid">
                <div class="kpi"><span class="kpi-value"><?= (int) $t['completed'] ?></span><span class="kpi-label">Completed visits</span></div>
                <div class="kpi"><span class="kpi-value"><?= e(money($t['spent'])) ?></span><span class="kpi-label">Total spent</span></div>
                <div class="kpi"><span class="kpi-value"><?= (int) $history['balance'] ?></span><span class="kpi-label">Loyalty points</span></div>
                <div class="kpi"><span class="kpi-value"><?= (int) $t['no_show'] ?> / <?= (int) $t['cancelled'] ?></span><span class="kpi-label">No-shows / cancelled</span></div>
                <div class="kpi"><span class="kpi-value"><?= (int) $t['upcoming'] ?></span><span class="kpi-label">Upcoming</span></div>
                <div class="kpi"><span class="kpi-value"><?= $t['last_visit'] ? e((new DateTimeImmutable($t['last_visit']))->format('j M Y')) : 'none' ?></span><span class="kpi-label">Last visit</span></div>
            </div>

            <h3>Visits</h3>
            <table class="schedule-table report-table">
                <thead><tr><th>Reference</th><th>Date</th><th>Service</th><th>Stylist</th><th>Status</th><th class="num">Price</th></tr></thead>
                <tbody>
                    <?php foreach ($history['visits'] as $v): ?>
                        <tr>
                            <td><?= e(bookingReference((int) $v['booking_id'])) ?></td>
                            <td><?= e((new DateTimeImmutable($v['start_time']))->format('D j M Y, H:i')) ?></td>
                            <td><?= e($v['service_name']) ?></td>
                            <td><?= e($v['stylist_name']) ?></td>
                            <td><?= e($v['status']) ?></td>
                            <td class="num"><?= e(money($v['price_charged'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ((int) $t['bookings'] > count($history['visits'])): ?><p class="meta">Showing the latest <?= count($history['visits']) ?> of <?= (int) $t['bookings'] ?> appointments.</p><?php endif; ?>

            <h3>Loyalty points</h3>
            <?php if (count($history['ledger']) === 0): ?><p>No points yet.</p><?php else: ?>
                <table class="schedule-table report-table">
                    <thead><tr><th>When</th><th class="num">Points</th><th>Type</th><th>Note</th></tr></thead>
                    <tbody>
                        <?php foreach ($history['ledger'] as $l): ?>
                            <tr>
                                <td><?= e((new DateTimeImmutable($l['created_at']))->format('j M Y, H:i')) ?></td>
                                <td class="num"><?= ((int) $l['points'] > 0 ? '+' : '') . (int) $l['points'] ?></td>
                                <td><?= e($l['reason']) ?></td>
                                <td><?= $l['note'] !== null ? e($l['note']) : '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
<?php reportClose();
