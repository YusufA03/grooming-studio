<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../classes/Booking.php';
require_once __DIR__ . '/../classes/Stylist.php';
require_once __DIR__ . '/../classes/Mailer.php';

// The token comes from the address (first visit) or from the form (when sent).
$input = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;
$token = inputText($input, 'token', '');

$booking     = null;
$stylist     = null;
$error       = null;
$formMessage = null;
$slots       = [];

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

if ($booking !== null && $error === null) {
    $problem = Booking::clientChangeProblem($booking);
    if ($problem !== '') {
        $error = $problem;
    } else {
        try {
            foreach (Stylist::getAll() as $s) {
                if ((int) $s['stylist_id'] === (int) $booking['stylist_id']) {
                    $stylist = $s;
                }
            }
        } catch (PDOException $ex) {
            error_log('Could not load stylists: ' . $ex->getMessage());
            $error = 'Sorry, something went wrong on our side. Please try again later.';
        }
        if ($stylist === null && $error === null) {
            $error = 'Sorry, this booking cannot be moved online. Please contact the salon.';
        }
    }
}

// ---- A new time was chosen ----
if ($error === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $startText = inputText($_POST, 'new_start', '');
    $newStart  = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $startText);

    if ($newStart === false || $newStart->format('Y-m-d H:i') !== $startText) {
        $formMessage = 'That time is not valid. Please choose a time from the list.';
    } else {
        try {
            $result = Booking::reschedule((int) $booking['booking_id'], $newStart, true, 'Client (private link)');
            if ($result === '') {
                // Send the updated confirmation. If the email fails the new time still stands.
                try {
                    $updated = Booking::findByToken($token);
                    if ($updated !== null) {
                        Mailer::sendBookingConfirmation($updated, $token, true);
                    }
                } catch (Throwable $mailEx) {
                    error_log('Reschedule email step failed: ' . $mailEx->getMessage());
                }
                header('Location: booking_confirmed.php?token=' . $token . '&msg=rescheduled');
                exit;
            }
            $formMessage = $result;
        } catch (Throwable $ex) {
            error_log('Could not reschedule: ' . $ex->getMessage());
            $formMessage = 'Sorry, something went wrong on our side. Please try again.';
        }
    }
}

// ---- Which day to show? (default: the booking's own day, or today if that has passed) ----
$date = null;
if ($error === null) {
    $dateInput = inputText($_GET, 'date', '');
    $today     = new DateTimeImmutable('today');
    $dateProblem = null;

    if ($dateInput !== '') {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput);
        if ($parsed === false || $parsed->format('Y-m-d') !== $dateInput) {
            $dateProblem = 'Please choose a valid date.';
        } elseif ($parsed < $today) {
            $dateProblem = 'Please choose today or a future date.';
        } else {
            $date = $parsed;
        }
    } else {
        $own  = (new DateTimeImmutable($booking['start_time']))->setTime(0, 0);
        $date = ($own < $today) ? $today : $own;
    }
    if ($dateProblem !== null) {
        $formMessage = $formMessage ?? $dateProblem;
    }

    // Find the free times for that day (the booking's own current slot counts as free for it).
    if ($date !== null) {
        try {
            $currentStart = new DateTimeImmutable($booking['start_time']);
            foreach (Booking::getAvailableSlots($stylist, (int) $booking['duration_minutes'], $date, (int) $booking['booking_id']) as $slot) {
                if ($slot != $currentStart) {
                    $slots[] = $slot;
                }
            }
        } catch (PDOException $ex) {
            error_log('Could not load times: ' . $ex->getMessage());
            $formMessage = 'Sorry, we could not load the available times. Please try again.';
        }
    }
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
    <title>Reschedule - Executive Grooming Studio</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <script src="assets/js/theme.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
</head>
<body>
    <header class="site-header">
        <h1>Executive Grooming Studio</h1>
        <p>Reschedule your booking</p>
    </header>

    <main class="container">
        <?php if ($booking !== null): ?>
            <p><a href="booking_confirmed.php?token=<?= e($token) ?>">&larr; Back to my booking</a></p>
        <?php else: ?>
            <p><a href="index.php">&larr; Back to services</a></p>
        <?php endif; ?>

        <?php if ($error): ?>
            <p class="error"><?= e($error) ?></p>
        <?php else: ?>
            <section class="service-card">
                <h3>Your current booking</h3>
                <p><strong><?= e($booking['service_name']) ?></strong> with <?= e($booking['stylist_name']) ?></p>
                <p><?= e($start->format('l, j F Y')) ?>, <?= e($start->format('H:i')) ?> to <?= e($end->format('H:i')) ?></p>
            </section>

            <h2>Choose a new day</h2>
            <form method="get" action="reschedule.php" class="booking-form">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <label for="date">Day</label>
                <input type="date" name="date" id="date"
                       min="<?= e((new DateTimeImmutable('today'))->format('Y-m-d')) ?>"
                       value="<?= e($date ? $date->format('Y-m-d') : '') ?>" required>
                <button type="submit" class="button">Show available times</button>
            </form>

            <?php if ($formMessage): ?>
                <p class="error"><?= e($formMessage) ?></p>
            <?php endif; ?>

            <?php if ($date !== null): ?>
                <h2>Available times on <?= e($date->format('l, j F Y')) ?></h2>
                <?php if (count($slots) === 0): ?>
                    <p>No other times are available on this day. Please try another day.</p>
                <?php else: ?>
                    <ul class="slot-list">
                        <?php foreach ($slots as $slot): ?>
                            <li class="slot">
                                <form method="post" action="reschedule.php">
                                    <input type="hidden" name="token" value="<?= e($token) ?>">
                                    <input type="hidden" name="new_start" value="<?= e($slot->format('Y-m-d H:i')) ?>">
                                    <button type="submit"><strong><?= e($slot->format('H:i')) ?></strong></button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
