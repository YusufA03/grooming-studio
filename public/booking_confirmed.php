<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../classes/Booking.php';

$booking = null;
$error   = null;

// The secret token must be exactly 64 letters/numbers (a-f, 0-9).
$token = (string) ($_GET['token'] ?? '');

if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    try {
        $booking = Booking::findByToken($token);
    } catch (PDOException $ex) {
        error_log('Could not load booking: ' . $ex->getMessage());
        $error = 'Sorry, something went wrong on our side. Please try again later.';
    }
}

if ($booking === null && $error === null) {
    http_response_code(404);
    $error = 'We could not find that booking.';
}

// Short messages shown after a change. Only these fixed texts can ever appear.
$notices = [
    'rescheduled' => ['success', 'Your booking has been moved. We have emailed you the new details.'],
    'cancelled'   => ['success', 'Your booking has been cancelled. We have emailed you a confirmation.'],
    'notchanged'  => ['error',   'Sorry, that change could not be made.'],
];
$notice = $notices[(string) ($_GET['msg'] ?? '')] ?? null;

$changeProblem = '';
if ($booking !== null) {
    $start         = new DateTimeImmutable($booking['start_time']);
    $end           = new DateTimeImmutable($booking['end_time']);
    $changeProblem = Booking::clientChangeProblem($booking);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your Booking - Executive Grooming Studio</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <script src="assets/js/theme.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
</head>
<body>
    <header class="site-header">
        <h1>Executive Grooming Studio</h1>
        <p>Your booking</p>
    </header>

    <main class="container">
        <?php if ($error): ?>
            <p class="error"><?= e($error) ?></p>
            <p><a href="index.php">&larr; Back to services</a></p>
        <?php else: ?>
            <?php if ($notice): ?>
                <p class="<?= $notice[0] ?>"><?= e($notice[1]) ?></p>
            <?php endif; ?>

            <?php if ($booking['status'] === 'confirmed'): ?>
                <p class="success">Thank you, <?= e($booking['client_name']) ?>! Your booking is confirmed.</p>
            <?php elseif ($booking['status'] === 'cancelled'): ?>
                <p class="error">This booking has been cancelled.</p>
            <?php else: ?>
                <p class="success">This booking is marked as <?= e($booking['status']) ?>.</p>
            <?php endif; ?>

            <section class="service-card">
                <h3>Booking reference #<?= (int) $booking['booking_id'] ?></h3>
                <p><strong><?= e($booking['service_name']) ?></strong> with <?= e($booking['stylist_name']) ?></p>
                <p><?= e($start->format('l, j F Y')) ?>, <?= e($start->format('H:i')) ?> to <?= e($end->format('H:i')) ?></p>
                <p class="meta">R<?= number_format((float) $booking['price'], 2) ?> (paid in the salon)</p>
                <?php if ($booking['notes']): ?>
                    <p><strong>Your notes:</strong><br><?= nl2br(e($booking['notes'])) ?></p>
                <?php endif; ?>
            </section>

            <?php if ($booking['status'] === 'confirmed'): ?>
                <h2>Need to change it?</h2>
                <?php if ($changeProblem === ''): ?>
                    <div class="actions">
                        <a class="button" href="reschedule.php?token=<?= e($token) ?>">Reschedule</a>
                        <form method="post" action="cancel_booking.php" class="inline-form"
                              onsubmit="return confirm('Cancel this booking?');">
                            <input type="hidden" name="token" value="<?= e($token) ?>">
                            <button type="submit" class="button">Cancel booking</button>
                        </form>
                    </div>
                <?php else: ?>
                    <p><?= e($changeProblem) ?></p>
                <?php endif; ?>
            <?php endif; ?>

            <p><a href="index.php">&larr; Back to services</a></p>
        <?php endif; ?>
    </main>
</body>
</html>
