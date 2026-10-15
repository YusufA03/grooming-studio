<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/admin_nav.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Booking.php';
require_once __DIR__ . '/../classes/Stylist.php';
require_once __DIR__ . '/../classes/Mailer.php';

// Lets the administrator move a client's appointment to another time (for example when the client
// phones to change it). It uses the same locked availability check as every other booking change,
// recalculates the end time from the service length, and emails the client the new details.
// Unlike a client, the administrator is not stopped by the 2-hour cut-off.

// A logged-in administrator (any booking) or stylist (their OWN bookings only) may open this page.
$user    = Auth::requireStaff();
$isAdmin = ($user['role'] === 'admin');

$input     = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;
$bookingId = filter_var($input['booking_id'] ?? null, FILTER_VALIDATE_INT);

$booking     = null;
$stylist     = null;
$error       = null;
$formMessage = null;
$slots       = [];

if ($bookingId === false || $bookingId < 1) {
    http_response_code(404);
    $error = 'We could not find that booking.';
} else {
    try {
        $booking = Booking::findDetails($bookingId);
    } catch (PDOException $ex) {
        error_log('Could not load booking for rescheduling: ' . $ex->getMessage());
        $error = 'Sorry, something went wrong on our side. Please try again later.';
    }
    // A stylist can only ever reach their own bookings; someone else's looks exactly like "no such booking".
    if ($booking !== null && !$isAdmin && (int) $booking['stylist_id'] !== (int) $user['id']) {
        $booking = null;
    }
    if ($booking === null && $error === null) {
        http_response_code(404);
        $error = 'We could not find that booking.';
    }
}

if ($booking !== null && $error === null) {
    if ($booking['status'] !== 'confirmed') {
        $error = 'Only a confirmed booking can be moved. This one is ' . $booking['status'] . '.';
    } elseif (new DateTimeImmutable($booking['start_time']) <= new DateTimeImmutable('now')) {
        $error = 'This appointment has already started, so it cannot be moved.';
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
            $error = 'This booking belongs to a stylist who no longer exists, so it cannot be moved here.';
        }
    }
}

// ---- A new time was chosen ----
if ($error === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::csrfValid($_POST['csrf_token'] ?? null)) {
        $formMessage = 'Your session expired. Please try again.';
    } else {
        $startText = inputText($_POST, 'new_start', '');
        $newStart  = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $startText);

        if ($newStart === false || $newStart->format('Y-m-d H:i') !== $startText) {
            $formMessage = 'That time is not valid. Please choose a time from the list.';
        } else {
            try {
                $result = Booking::reschedule((int) $booking['booking_id'], $newStart, false, Auth::actorLabel($user));
                if ($result === '') {
                    // Email the client the new details (if they gave an email). The new time stands either way.
                    $emailNote = ' This client has no email address, so nobody was emailed.';
                    if (!empty($booking['client_email'])) {
                        $sent = false;
                        try {
                            $updated = Booking::findByToken($booking['manage_token']);
                            if ($updated !== null) {
                                $sent = Mailer::sendBookingConfirmation($updated, $booking['manage_token'], true);
                            }
                        } catch (Throwable $mailEx) {
                            error_log('Staff reschedule email step failed: ' . $mailEx->getMessage());
                        }
                        $emailNote = $sent ? ' The client was emailed the new details.' : ' The email to the client could NOT be sent.';
                    }
                    $_SESSION['flash'] = ['success', 'Booking #' . (int) $booking['booking_id'] . ' for ' . $booking['client_name']
                        . ' was moved to ' . $newStart->format('D j M') . ' at ' . $newStart->format('H:i') . '.' . $emailNote];

                    $day = $newStart->format('Y-m-d');
                    header('Location: ' . ($isAdmin
                        ? 'admin_dashboard.php?' . http_build_query(['from' => $day, 'to' => $day, 'stylist_id' => (int) $booking['stylist_id'], 'status' => '', 'q' => ''])
                        : 'stylist_schedule.php?' . http_build_query(['view' => 'day', 'date' => $day])));
                    exit;
                }
                $formMessage = $result;
            } catch (Throwable $ex) {
                error_log('Could not reschedule (staff): ' . $ex->getMessage());
                $formMessage = 'Sorry, something went wrong on our side. Please try again.';
            }
        }
    }
}

// ---- Which day to show? (default: the booking's own day, or today if that has passed) ----
$date = null;
if ($error === null) {
    $dateInput   = inputText($_GET, 'date', '');
    $today       = new DateTimeImmutable('today');
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

    // The free times that day (the booking's own current slot is not offered as a "new" time).
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
    <title>Reschedule a Booking - Executive Grooming Studio</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <script src="assets/js/theme.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
</head>
<body>
    <header class="site-header">
        <h1>Executive Grooming Studio</h1>
        <p><?= $isAdmin ? 'Administrator Dashboard' : 'Stylist' ?></p>
    </header>

    <main class="container">
        <div class="topbar">
            <span>Logged in as <strong><?= e($user['name']) ?></strong> (<?= $isAdmin ? 'administrator' : 'stylist' ?>)</span>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <button type="submit" class="button">Log out</button>
            </form>
        </div>

        <?= $isAdmin ? adminNav('bookings') : '' ?>

        <p><?= $isAdmin ? '<a href="admin_dashboard.php">&larr; Back to all bookings</a>' : '<a href="stylist_schedule.php">&larr; Back to my schedule</a>' ?></p>
        <h2>Reschedule a booking</h2>

        <?php if ($error): ?>
            <p class="error"><?= e($error) ?></p>
        <?php else: ?>
            <section class="service-card">
                <h3>Booking #<?= (int) $booking['booking_id'] ?></h3>
                <p><strong><?= e($booking['client_name']) ?></strong>
                    <?php if ($isAdmin): ?>(<?= ($booking['client_email'] !== null && $booking['client_email'] !== '') ? e($booking['client_email']) : 'no email' ?>)<?php endif; ?></p>
                <p><?= e($booking['service_name']) ?> with <?= e($booking['stylist_name']) ?> (<?= (int) $booking['duration_minutes'] ?> min)</p>
                <p>Now: <?= e($start->format('l, j F Y')) ?>, <?= e($start->format('H:i')) ?> to <?= e($end->format('H:i')) ?></p>
            </section>

            <h3>Choose a new day</h3>
            <form method="get" action="admin_reschedule.php" class="booking-form">
                <input type="hidden" name="booking_id" value="<?= (int) $booking['booking_id'] ?>">
                <label for="date">Day</label>
                <input type="date" name="date" id="date" required
                       min="<?= e((new DateTimeImmutable('today'))->format('Y-m-d')) ?>"
                       value="<?= e($date ? $date->format('Y-m-d') : '') ?>">
                <button type="submit" class="button">Show free times</button>
            </form>

            <?php if ($formMessage): ?>
                <p class="error"><?= e($formMessage) ?></p>
            <?php endif; ?>

            <?php if ($date !== null): ?>
                <h3>Free times on <?= e($date->format('l, j F Y')) ?></h3>
                <?php if (count($slots) === 0): ?>
                    <p>No other times are free on this day. Try another day.</p>
                <?php else: ?>
                    <ul class="slot-list">
                        <?php foreach ($slots as $slot): ?>
                            <li class="slot">
                                <form method="post" action="admin_reschedule.php"
                                      onsubmit="return confirm('Move this booking to <?= e($slot->format('D j M \a\t H:i')) ?>?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                    <input type="hidden" name="booking_id" value="<?= (int) $booking['booking_id'] ?>">
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
