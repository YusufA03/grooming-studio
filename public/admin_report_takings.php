<?php
require_once __DIR__ . '/../config/report_helpers.php';
require_once __DIR__ . '/../classes/Report.php';

// REPORT 2: Takings. The value of COMPLETED appointments, day by day and service by service: the figure the till should agree with.
$user = Auth::requireRole('admin');
[$from, $to, $problem] = readPeriod();

$byDay = $byService = [];
$rewards = ['points' => 0, 'value' => 0];
$loadError = null;
try {
    $byDay     = Report::takingsByDay($from, $to);
    $byService = Report::takingsByService($from, $to);
    $rewards   = Report::rewardsGiven($from, $to);
} catch (PDOException $ex) {
    error_log('Takings report failed: ' . $ex->getMessage());
    $loadError = 'Sorry, something went wrong on our side. Please try again later.';
}
$total = 0.0; $count = 0;
foreach ($byDay as $d) { $total += (float) $d['value']; $count += (int) $d['appointments']; }

if ($loadError === null && inputText($_GET, 'export', '') === 'csv') {
    $out = [['Takings'], ['Period', $from->format('Y-m-d'), $to->format('Y-m-d')], [], ['DAY BY DAY'], ['Date', 'Completed appointments', 'Value (R)']];
    foreach ($byDay as $d) { $out[] = [$d['day'], $d['appointments'], number_format((float) $d['value'], 2, '.', '')]; }
    $out[] = ['Total', $count, number_format($total, 2, '.', '')];
    $out[] = []; $out[] = ['SERVICE BY SERVICE']; $out[] = ['Service', 'Completed appointments', 'Value (R)'];
    foreach ($byService as $s) { $out[] = [$s['name'], $s['appointments'], number_format((float) $s['value'], 2, '.', '')]; }
    $out[] = ['Total', $count, number_format($total, 2, '.', '')];
    $out[] = []; $out[] = ['Loyalty rewards given (R)', $rewards['value']]; $out[] = ['Takings less rewards (R)', number_format($total - $rewards['value'], 2, '.', '')];
    sendCsv('takings-' . $from->format('Y-m-d') . '-to-' . $to->format('Y-m-d') . '.csv', $out);
}

reportOpen($user, 'Takings', $from->format('j M Y') . ' to ' . $to->format('j M Y'));
reportToolbar($from, $to, $problem);
?>
        <?php if ($loadError): ?><p class="error"><?= e($loadError) ?></p><?php else: ?>
        <div class="kpi-grid">
            <div class="kpi"><span class="kpi-value"><?= e(money($total)) ?></span><span class="kpi-label">Takings from completed appointments</span></div>
            <div class="kpi"><span class="kpi-value"><?= (int) $count ?></span><span class="kpi-label">Completed appointments</span></div>
            <div class="kpi"><span class="kpi-value"><?= $count > 0 ? e(money($total / $count)) : 'n/a' ?></span><span class="kpi-label">Average per appointment</span></div>
        </div>

        <h3>Day by day</h3>
        <?php if (count($byDay) === 0): ?><p>No completed appointments in this period.</p><?php else: ?>
            <table class="schedule-table report-table">
                <thead><tr><th>Date</th><th class="num">Completed</th><th class="num">Value</th></tr></thead>
                <tbody>
                    <?php foreach ($byDay as $d): ?>
                        <tr><td><?= e((new DateTimeImmutable($d['day']))->format('D j M Y')) ?></td><td class="num"><?= (int) $d['appointments'] ?></td><td class="num"><?= e(money($d['value'])) ?></td></tr>
                    <?php endforeach; ?>
                    <tr class="total-row"><td>Total</td><td class="num"><?= (int) $count ?></td><td class="num"><?= e(money($total)) ?></td></tr>
                </tbody>
            </table>

            <h3>Service by service</h3>
            <table class="schedule-table report-table">
                <thead><tr><th>Service</th><th class="num">Completed</th><th class="num">Value</th></tr></thead>
                <tbody>
                    <?php foreach ($byService as $s): ?>
                        <tr><td><?= e($s['name']) ?></td><td class="num"><?= (int) $s['appointments'] ?></td><td class="num"><?= e(money($s['value'])) ?></td></tr>
                    <?php endforeach; ?>
                    <tr class="total-row"><td>Total</td><td class="num"><?= (int) $count ?></td><td class="num"><?= e(money($total)) ?></td></tr>
                </tbody>
            </table>
        <?php endif; ?>

        <h3>Loyalty rewards</h3>
        <p>Rewards given in this period: <strong><?= e(money($rewards['value'])) ?></strong> (<?= (int) $rewards['points'] ?> points exchanged).
            If these discounts were taken off the till, the expected till figure is <strong><?= e(money($total - $rewards['value'])) ?></strong>.</p>
        <?php endif; ?>
<?php reportClose();
