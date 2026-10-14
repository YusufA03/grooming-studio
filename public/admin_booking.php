<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/admin_nav.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Booking.php';

// One booking and its complete history: who made it, moved it, cancelled it or marked it, and when.
// This is the audit trail (Phase 3, Section 3.4), used to settle a scheduling dispute.

// Only a logged-in administrator may open this page.
$user = Auth::requireRole('admin');

$bookingId = filter_var($_GET['booking_id'] ?? null, FILTER_VALIDATE_INT);
$booking   = null;
$history   = [];
$error     = null;

if ($bookingId === false || $bookingId < 1) {
    http_response_code(404);
    $error = 'We could not find that booking.';
} else {
    try {
        $booking = Booking::findDetails($bookingId);
        if ($booking === null) {
            http_response_code(404);
            $error = 'We could not find that booking.';
        } else {
            $history = AuditLog::forBooking($bookingId);
        }
    } catch (PDOException $ex) {
        error_log('Could not load booking history: ' . $ex->getMessage());
        $error = 'Sorry, something went wrong on our side. Please try again later.';
    }
}

$labels = [
    'created'       => 'Booked',
    'rescheduled'   => 'Rescheduled',
    'cancelled'     => 'Cancelled',
    'completed'     => 'Marked completed',
    'no-show'       => 'Marked no-show',
    'reminder-sent' => 'Reminder emailed',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Booking History - Executive Grooming Studio</title>
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

        <?= adminNav('bookings') ?>

        <p><a href="admin_dashboard.php">&larr; Back to all bookings</a></p>
        <h2>Booking history</h2>

        <?php if ($error): ?>
            <p class="error"><?= e($error) ?></p>
        <?php else: ?>
            <?php $start = new DateTimeImmutable($booking['start_time']); $end = new DateTimeImmutable($booking['end_time']); ?>
            <section class="service-card">
                <h3>Booking #<?= (int) $booking['booking_id'] ?>: <?= e($booking['status']) ?></h3>
                <p><strong><?= e($booking['client_name']) ?></strong>
                    (<?= ($booking['client_email'] !== null && $booking['client_email'] !== '') ? e($booking['client_email']) : 'no email' ?><?= !empty($booking['client_phone']) ? ', ' . e($booking['client_phone']) : '' ?>)</p>
                <p><?= e($booking['service_name']) ?> with <?= e($booking['stylist_name']) ?>
                    &middot; R<?= number_format((float) $booking['price_charged'], 2) ?></p>
                <p><?= e($start->format('l, j F Y')) ?>, <?= e($start->format('H:i')) ?> to <?= e($end->format('H:i')) ?></p>
                <?php if (!empty($booking['allergies'])): ?>
                    <span class="allergy-alert">Allergies: <?= e($booking['allergies']) ?></span>
                <?php endif; ?>
                <?php if (!empty($booking['notes'])): ?>
                    <p><strong>Notes:</strong> <?= nl2br(e($booking['notes'])) ?></p>
                <?php endif; ?>
            </section>

            <h3>History</h3>
            <?php if (count($history) === 0): ?>
                <p>No history was recorded for this booking. It was made before the audit trail was introduced.</p>
            <?php else: ?>
                <table class="schedule-table">
                    <thead>
                        <tr><th>When</th><th>What happened</th><th>Done by</th><th>Details</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $line): ?>
                            <tr>
                                <td><?= e((new DateTimeImmutable($line['created_at']))->format('j M Y, H:i')) ?></td>
                                <td><?= e($labels[$line['action']] ?? $line['action']) ?></td>
                                <td><?= e($line['actor']) ?></td>
                                <td><?= $line['detail'] !== null ? e($line['detail']) : '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
