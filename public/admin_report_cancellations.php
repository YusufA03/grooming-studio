<?php
require_once __DIR__ . '/../config/report_helpers.php';
require_once __DIR__ . '/../classes/Report.php';

// REPORT 4: Cancellations and no-shows. Who cancelled what, when, and how much notice the salon was given, and which clients did not arrive.
$user = Auth::requireRole('admin');
[$from, $to, $problem] = readPeriod();

$cancels = $noShows = [];
$loadError = null;
try {
    $cancels = Report::cancellations($from, $to);
    $noShows = Report::noShows($from, $to);
} catch (PDOException $ex) {
    error_log('Cancellations report failed: ' . $ex->getMessage());
    $loadError = 'Sorry, something went wrong on our side. Please try again later.';
}
$cancelValue = array_sum(array_map(fn ($r) => (float) $r['price_charged'], $cancels));
$noShowValue = array_sum(array_map(fn ($r) => (float) $r['price_charged'], $noShows));
$late = count(array_filter($cancels, fn ($r) => $r['notice_minutes'] !== null && $r['notice_minutes'] < 120));
$known = array_filter($cancels, fn ($r) => $r['notice_minutes'] !== null);
$avgNotice = count($known) > 0 ? (int) round(array_sum(array_map(fn ($r) => max(0, $r['notice_minutes']), $known)) / count($known)) : null;
$perClient = [];
foreach ($noShows as $r) {
    $k = (int) $r['client_id'];
    $perClient[$k] = $perClient[$k] ?? ['name' => $r['client_name'], 'n' => 0, 'value' => 0.0];
    $perClient[$k]['n']++;
    $perClient[$k]['value'] += (float) $r['price_charged'];
}
uasort($perClient, fn ($a, $b) => [$b['n'], $a['name']] <=> [$a['n'], $b['name']]);

if ($loadError === null && inputText($_GET, 'export', '') === 'csv') {
    $out = [['Cancellations and no-shows'], ['Period', $from->format('Y-m-d'), $to->format('Y-m-d')], [], ['CANCELLATIONS'],
            ['Reference', 'Appointment', 'Client', 'Service', 'Stylist', 'Price (R)', 'Cancelled at', 'Cancelled by', 'Notice (minutes)']];
    foreach ($cancels as $r) {
        $out[] = [bookingReference((int) $r['booking_id']), substr($r['start_time'], 0, 16), $r['client_name'], $r['service_name'], $r['stylist_name'],
                  number_format((float) $r['price_charged'], 2, '.', ''), $r['cancelled_at'] === null ? '' : substr($r['cancelled_at'], 0, 16), $r['cancelled_by'] ?? '',
                  $r['notice_minutes'] === null ? '' : $r['notice_minutes']];
    }
    $out[] = ['Cancelled', count($cancels), 'Value (R)', number_format($cancelValue, 2, '.', '')];
    $out[] = []; $out[] = ['NO-SHOWS']; $out[] = ['Reference', 'Appointment', 'Client', 'Service', 'Stylist', 'Price (R)'];
    foreach ($noShows as $r) { $out[] = [bookingReference((int) $r['booking_id']), substr($r['start_time'], 0, 16), $r['client_name'], $r['service_name'], $r['stylist_name'], number_format((float) $r['price_charged'], 2, '.', '')]; }
    $out[] = ['No-shows', count($noShows), 'Value (R)', number_format($noShowValue, 2, '.', '')];
    $out[] = []; $out[] = ['CLIENTS WHO DID NOT ARRIVE']; $out[] = ['Client', 'No-shows', 'Value (R)'];
    foreach ($perClient as $c) { $out[] = [$c['name'], $c['n'], number_format($c['value'], 2, '.', '')]; }
    sendCsv('cancellations-and-no-shows-' . $from->format('Y-m-d') . '-to-' . $to->format('Y-m-d') . '.csv', $out);
}

reportOpen($user, 'Cancellations and no-shows', $from->format('j M Y') . ' to ' . $to->format('j M Y'));
reportToolbar($from, $to, $problem);
?>
        <?php if ($loadError): ?><p class="error"><?= e($loadError) ?></p><?php else: ?>
        <div class="kpi-grid">
            <div class="kpi"><span class="kpi-value"><?= count($cancels) ?></span><span class="kpi-label">Cancelled (<?= e(money($cancelValue)) ?>)</span></div>
            <div class="kpi"><span class="kpi-value"><?= count($noShows) ?></span><span class="kpi-label">No-shows (<?= e(money($noShowValue)) ?>)</span></div>
            <div class="kpi"><span class="kpi-value"><?= (int) $late ?></span><span class="kpi-label">Cancelled with under 2 hours notice</span></div>
            <div class="kpi"><span class="kpi-value"><?= e($avgNotice === null ? 'n/a' : noticeText($avgNotice)) ?></span><span class="kpi-label">Average notice given</span></div>
        </div>

        <h3>Cancellations</h3>
        <?php if (count($cancels) === 0): ?><p>No cancellations in this period.</p><?php else: ?>
            <table class="schedule-table report-table">
                <thead><tr><th>Reference</th><th>Appointment</th><th>Client</th><th>Service</th><th>Stylist</th><th class="num">Price</th><th>Cancelled</th><th>By</th><th>Notice given</th></tr></thead>
                <tbody>
                    <?php foreach ($cancels as $r): ?>
                        <tr>
                            <td><?= e(bookingReference((int) $r['booking_id'])) ?></td>
                            <td><?= e((new DateTimeImmutable($r['start_time']))->format('D j M, H:i')) ?></td>
                            <td><?= e($r['client_name']) ?></td>
                            <td><?= e($r['service_name']) ?></td>
                            <td><?= e($r['stylist_name']) ?></td>
                            <td class="num"><?= e(money($r['price_charged'])) ?></td>
                            <td><?= $r['cancelled_at'] === null ? 'not recorded' : e((new DateTimeImmutable($r['cancelled_at']))->format('j M, H:i')) ?></td>
                            <td><?= e($r['cancelled_by'] ?? 'not recorded') ?></td>
                            <td><?= e(noticeText($r['notice_minutes'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3>No-shows</h3>
        <?php if (count($noShows) === 0): ?><p>No no-shows in this period.</p><?php else: ?>
            <table class="schedule-table report-table">
                <thead><tr><th>Reference</th><th>Appointment</th><th>Client</th><th>Service</th><th>Stylist</th><th class="num">Price</th></tr></thead>
                <tbody>
                    <?php foreach ($noShows as $r): ?>
                        <tr>
                            <td><?= e(bookingReference((int) $r['booking_id'])) ?></td>
                            <td><?= e((new DateTimeImmutable($r['start_time']))->format('D j M, H:i')) ?></td>
                            <td><?= e($r['client_name']) ?></td>
                            <td><?= e($r['service_name']) ?></td>
                            <td><?= e($r['stylist_name']) ?></td>
                            <td class="num"><?= e(money($r['price_charged'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <h3>Clients who did not arrive</h3>
            <table class="schedule-table report-table">
                <thead><tr><th>Client</th><th class="num">No-shows</th><th class="num">Value</th></tr></thead>
                <tbody><?php foreach ($perClient as $c): ?><tr><td><?= e($c['name']) ?></td><td class="num"><?= (int) $c['n'] ?></td><td class="num"><?= e(money($c['value'])) ?></td></tr><?php endforeach; ?></tbody>
            </table>
        <?php endif; ?>
        <p class="meta">Notice given is the time between the cancellation and the start of the appointment, taken from the booking history. A booking cancelled before the history was introduced shows "not recorded".</p>
        <?php endif; ?>
<?php reportClose();
