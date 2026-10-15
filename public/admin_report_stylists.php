<?php
require_once __DIR__ . '/../config/report_helpers.php';
require_once __DIR__ . '/../classes/Report.php';

// REPORT 3: Stylist activity. Hours rostered and booked, what was completed, and what was lost to cancellation.
$user = Auth::requireRole('admin');
[$from, $to, $problem] = readPeriod();

$rows = [];
$loadError = null;
try {
    $rows = Report::stylistActivity($from, $to);
} catch (PDOException $ex) {
    error_log('Stylist activity report failed: ' . $ex->getMessage());
    $loadError = 'Sorry, something went wrong on our side. Please try again later.';
}
$hours = static fn ($minutes): string => number_format(((int) $minutes) / 60, 1);
$pct   = static fn (?float $r): string => $r === null ? 'n/a' : number_format($r * 100, 1) . '%';

if ($loadError === null && inputText($_GET, 'export', '') === 'csv') {
    $out = [['Stylist activity'], ['Period', $from->format('Y-m-d'), $to->format('Y-m-d')], [],
            ['Stylist', 'Hours rostered', 'Hours booked', 'Utilisation', 'Completed', 'No-shows', 'Cancelled', 'Hours lost to cancellation', 'Upcoming', 'Value of completed (R)']];
    foreach ($rows as $r) {
        $out[] = [$r['name'], $hours($r['rostered_minutes']), $hours($r['booked_minutes']), $r['utilisation'] === null ? '' : round($r['utilisation'] * 100, 1) . '%',
                  $r['completed'], $r['no_show'], $r['cancelled'], $hours($r['cancelled_minutes']), $r['upcoming'], number_format((float) $r['value'], 2, '.', '')];
    }
    sendCsv('stylist-activity-' . $from->format('Y-m-d') . '-to-' . $to->format('Y-m-d') . '.csv', $out);
}

reportOpen($user, 'Stylist activity', $from->format('j M Y') . ' to ' . $to->format('j M Y'));
reportToolbar($from, $to, $problem);
?>
        <?php if ($loadError): ?><p class="error"><?= e($loadError) ?></p><?php else: ?>
        <table class="schedule-table report-table">
            <thead><tr><th>Stylist</th><th class="num">Hours rostered</th><th class="num">Hours booked</th><th class="num">Utilisation</th><th class="num">Completed</th><th class="num">No-shows</th><th class="num">Cancelled</th><th class="num">Hours lost to cancellation</th><th class="num">Upcoming</th><th class="num">Value of completed</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['name']) ?><?= ((int) $r['accepting_bookings'] === 1) ? '' : ' <span class="meta">(not taking bookings)</span>' ?></td>
                        <td class="num"><?= e($hours($r['rostered_minutes'])) ?></td>
                        <td class="num"><?= e($hours($r['booked_minutes'])) ?></td>
                        <td class="num"><?= e($pct($r['utilisation'])) ?></td>
                        <td class="num"><?= (int) $r['completed'] ?></td>
                        <td class="num"><?= (int) $r['no_show'] ?></td>
                        <td class="num"><?= (int) $r['cancelled'] ?></td>
                        <td class="num"><?= e($hours($r['cancelled_minutes'])) ?></td>
                        <td class="num"><?= (int) $r['upcoming'] ?></td>
                        <td class="num"><?= e(money($r['value'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="meta">How the figures are worked out. Hours rostered are the stylist's working blocks counted for every day of the period. Hours booked are the length of upcoming,
            completed and no-show appointments (a no-show still used the time). Utilisation is hours booked divided by hours rostered. Appointments are counted on the day they start.
            Hours lost to cancellation are the length of cancelled appointments.</p>
        <?php endif; ?>
<?php reportClose();
