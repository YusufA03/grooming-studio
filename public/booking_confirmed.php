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

if ($booking !== null) {
    $start = new DateTimeImmutable($booking['start_time']);
    $end   = new DateTimeImmutable($booking['end_time']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Booking Confirmed - Executive Grooming Studio</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="site-header">
        <h1>Executive Grooming Studio</h1>
        <p>Booking confirmation</p>
    </header>

    <main class="container">
        <?php if ($error): ?>
            <p class="error"><?= e($error) ?></p>
            <p><a href="index.php">&larr; Back to services</a></p>
        <?php else: ?>
            <p class="success">Thank you, <?= e($booking['client_name']) ?>! Your booking is confirmed.</p>

            <section class="service-card">
                <h3>Booking reference #<?= (int) $booking['booking_id'] ?></h3>
                <p><strong><?= e($booking['service_name']) ?></strong> with <?= e($booking['stylist_name']) ?></p>
                <p><?= e($start->format('l, j F Y')) ?>, <?= e($start->format('H:i')) ?> to <?= e($end->format('H:i')) ?></p>
                <p class="meta">R<?= number_format((float) $booking['price'], 2) ?> (paid in the salon)</p>
                <?php if ($booking['notes']): ?>
                    <p><strong>Your notes:</strong><br><?= nl2br(e($booking['notes'])) ?></p>
                <?php endif; ?>
            </section>

            <p><a class="button" href="index.php">Back to services</a></p>
        <?php endif; ?>
    </main>
</body>
</html>