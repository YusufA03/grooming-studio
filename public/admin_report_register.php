<?php
require_once __DIR__ . '/../config/report_helpers.php';
require_once __DIR__ . '/../classes/Report.php';

// REPORT 1: Bookings register. Every appointment in the period, optionally one stylist, one status, or a search by client or reference.
$user = Auth::requireRole('admin');
[$from, $to, $problem] = readPeriod();

$stylistId = filter_var($_GET['stylist_id'] ?? null, FILTER_VALIDATE_INT);
$stylistId = ($stylistId !== false && $stylistId > 0) ? (int) $stylistId : null;
$statusIn  = inputText($_GET, 'status', '');
$status    = in_array($statusIn, ['confirmed', 'completed', 'no-show', 'cancelled'], true) ? $statusIn : null;
$search    = mb_substr(trim(inputText($_GET, 'q', '')), 0, 100);

$rows = $stylists = [];
$loadError = null;
try {
    $stylists = Stylist::getAll();
    $rows     = Report::register($from, $to, $stylistId, $status, $search);
} catch (PDOException $ex) {
    error_log('Bookings register failed: ' . $ex->getMessage());
    $loadError = 'Sorry, something went wrong on our side. Please try again later.';
}
$truncated = count($rows) > 2000;
if ($truncated) { array_pop($rows); }

$counts = ['confirmed' => 0, 'completed' => 0, 'no-show' => 0, 'cancelled' => 0];
$value  = 0.0;
foreach ($rows as $r) {
    $counts[$r['status']]++;
    if ($r['status'] === 'completed') { $value += (float) $r['price_charged']; }
}
$stylistName = 'All stylists';
foreach ($stylists as $s) { if ($stylistId === (int) $s['stylist_id']) { $stylistName = $s['name']; } }

if ($loadError === null && inputText($_GET, 'export', '') === 'csv') {
    $out = [
        ['Bookings register'], ['Period', $from->format('Y-m-d'), $to->format('Y-m-d')], ['Stylist', $stylistName], ['Status', $status ?? 'all'], ['Search', $search], [],
        ['Reference', 'Date', 'Start', 'End', 'Client', 'Service', 'Stylist', 'Status', 'Price (R)'],
    ];
    foreach ($rows as $r) {
        $out[] = [bookingReference((int) $r['booking_id']), substr($r['start_time'], 0, 10), substr($r['start_time'], 11, 5), substr($r['end_time'], 11, 5),
                  $r['client_name'], $r['service_name'], $r['stylist_name'], $r['status'], number_format((float) $r['price_charged'], 2, '.', '')];
    }
    $out[] = [];
    $out[] = ['Appointments', count($rows)];
    $out[] = ['Completed', $counts['completed'], 'Value of completed (R)', number_format($value, 2, '.', '')];
    $out[] = ['Upcoming', $counts['confirmed']]; $out[] = ['No-shows', $counts['no-show']]; $out[] = ['Cancelled', $counts['cancelled']];
    if ($truncated) { $out[] = ['Only the first 2000 appointments are shown. Choose a shorter period.']; }
    sendCsv('bookings-register-' . $from->format('Y-m-d') . '-to-' . $to->format('Y-m-d') . '.csv', $out);
}

reportOpen($user, 'Bookings register', $from->format('j M Y') . ' to ' . $to->format('j M Y'));
$filters = '<label for="stylist_id">Stylist</label><select name="stylist_id" id="stylist_id"><option value="">All stylists</option>';
foreach ($stylists as $s) { $filters .= '<option value="' . (int) $s['stylist_id'] . '"' . ($stylistId === (int) $s['stylist_id'] ? ' selected' : '') . '>' . e($s['name']) . '</option>'; }
$filters .= '</select><label for="status">Status</label><select name="status" id="status"><option value="">Any status</option>';
foreach (['confirmed' => 'Upcoming (confirmed)', 'completed' => 'Completed', 'no-show' => 'No-show', 'cancelled' => 'Cancelled'] as $v => $label) { $filters .= '<option value="' . $v . '"' . ($status === $v ? ' selected' : '') . '>' . e($label) . '</option>'; }
$filters .= '</select><label for="q">Client or reference</label><input type="text" name="q" id="q" value="' . e($search) . '" maxlength="100" placeholder="Name or EGS-00042">';
$extra = array_filter(['stylist_id' => $stylistId, 'status' => $status, 'q' => $search], fn ($v) => $v !== null && $v !== '');
reportToolbar($from, $to, $problem, $extra, $filters);
?>
        <?php if ($loadError): ?><p class="error"><?= e($loadError) ?></p><?php else: ?>
        <p class="meta"><?= e($stylistName) ?><?= $status ? ' &middot; ' . e($status) : '' ?><?= $search !== '' ? ' &middot; search: ' . e($search) : '' ?></p>
        <?php if (count($rows) === 0): ?>
            <p>There are no appointments for these choices.</p>
        <?php else: ?>
            <table class="schedule-table report-table">
                <thead><tr><th>Reference</th><th>Date</th><th>Time</th><th>Client</th><th>Service</th><th>Stylist</th><th>Status</th><th class="num">Price</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= e(bookingReference((int) $r['booking_id'])) ?></td>
                            <td><?= e((new DateTimeImmutable($r['start_time']))->format('D j M Y')) ?></td>
                            <td><?= e(substr($r['start_time'], 11, 5)) ?>&ndash;<?= e(substr($r['end_time'], 11, 5)) ?></td>
                            <td><?= e($r['client_name']) ?></td>
                            <td><?= e($r['service_name']) ?></td>
                            <td><?= e($r['stylist_name']) ?></td>
                            <td><?= e($r['status']) ?></td>
                            <td class="num"><?= e(money($r['price_charged'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($truncated): ?><p class="warning">Only the first 2000 appointments are shown. Choose a shorter period or narrow the search.</p><?php endif; ?>
            <p><strong><?= count($rows) ?> appointment<?= count($rows) === 1 ? '' : 's' ?></strong>:
                <?= (int) $counts['completed'] ?> completed (<?= e(money($value)) ?>), <?= (int) $counts['confirmed'] ?> upcoming,
                <?= (int) $counts['no-show'] ?> no-show, <?= (int) $counts['cancelled'] ?> cancelled.</p>
        <?php endif; ?>
        <?php endif; ?>
<?php reportClose();
