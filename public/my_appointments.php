<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/account_bar.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Booking.php';
require_once __DIR__ . '/../classes/Loyalty.php';

// A signed-in client's own appointments and loyalty points. Only the client's own bookings are ever listed.

$user = Auth::requireClient();

$upcoming = [];
$past     = [];
$points   = null;
$error    = null;

try {
    $now = new DateTimeImmutable('now');
    foreach (Booking::forClient((int) $user['client_id']) as $b) {
        if ($b['status'] === 'confirmed' && new DateTimeImmutable($b['start_time']) >= $now) {
            $upcoming[] = $b;
        } else {
            $past[] = $b;
        }
    }
    $upcoming = array_reverse($upcoming);   // soonest first
    $points   = Loyalty::balance((int) $user['client_id']);
} catch (PDOException $ex) {
    error_log('Could not load appointments: ' . $ex->getMessage());
    $error = 'Sorry, something went wrong on our side. Please try again later.';
}

function appointmentCard(array $b): string
{
    $start = new DateTimeImmutable($b['start_time']);
    $end   = new DateTimeImmutable($b['end_time']);
    return '<article class="appt-card status-' . e($b['status']) . '">'
        . '<div class="appt-top"><span class="appt-date">' . e(strtoupper($start->format('D j M Y'))) . '</span>'
        . '<span class="status-badge">' . e($b['status']) . '</span></div>'
        . '<p class="appt-time">' . e($start->format('H:i')) . ' &ndash; ' . e($end->format('H:i')) . '</p>'
        . '<p><strong>' . e($b['service_name']) . '</strong> with ' . e($b['stylist_name']) . '</p>'
        . '<div class="appt-bottom"><span class="appt-ref">' . e(bookingReference((int) $b['booking_id']))
        . ' &middot; R' . number_format((float) $b['price_charged'], 2) . '</span>'
        . '<a class="button" href="booking_confirmed.php?token=' . e($b['manage_token']) . '">View</a></div>'
        . '</article>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My appointments - Executive Grooming Studio</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <script src="assets/js/theme.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
</head>
<body>
    <header class="site-header">
        <h1>Executive Grooming Studio</h1>
        <p>My account</p>
    </header>
    <?= accountBar() ?>

    <main class="container">
        <div class="appt-heading">
            <h2>My appointments</h2>
            <?php if ($points !== null): ?><span class="points-badge"><strong><?= (int) $points ?></strong> loyalty points</span><?php endif; ?>
        </div>
        <?php if ($error): ?>
            <p class="error"><?= e($error) ?></p>
        <?php else: ?>
            <h3>Upcoming</h3>
            <?php if (count($upcoming) === 0): ?>
                <div class="appt-empty"><p>Nothing coming up.</p><a class="button" href="index.php">Book an appointment</a></div>
            <?php else: ?>
                <?php foreach ($upcoming as $b) { echo appointmentCard($b); } ?>
            <?php endif; ?>

            <h3>Past and cancelled</h3>
            <?php if (count($past) === 0): ?>
                <p>No past appointments yet.</p>
            <?php else: ?>
                <?php foreach ($past as $b) { echo appointmentCard($b); } ?>
            <?php endif; ?>
            <p class="meta">Earn 1 loyalty point for every R<?= (int) Loyalty::RAND_PER_POINT ?> of a completed appointment.
                Ask in the salon to exchange <?= (int) Loyalty::REDEEM_POINTS ?> points for R<?= (int) Loyalty::REDEEM_VALUE ?> off.</p>
        <?php endif; ?>
    </main>
</body>
</html>
