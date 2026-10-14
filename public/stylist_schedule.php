<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Booking.php';

// Only a logged-in stylist may open this page. The stylist is taken from the
// login session, never from the address, so nobody can look at another
// stylist's schedule by changing a number in the URL.
$user      = Auth::requireRole('stylist');
$stylistId = (int) $user['id'];

// ---- Which view (day or week) and which date? ----
$input = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;
$view  = (($input['view'] ?? 'day') === 'week') ? 'week' : 'day';

$date        = new DateTimeImmutable('today');
$dateProblem = null;
$dateInput   = (string) ($input['date'] ?? '');
if ($dateInput !== '') {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput);
    if ($parsed !== false && $parsed->format('Y-m-d') === $dateInput) {
        $date = $parsed;
    } else {
        $dateProblem = 'That date was not valid, so today is shown instead.';
    }
}

function scheduleUrl(string $view, DateTimeImmutable $date): string
{
    return 'stylist_schedule.php?' . http_build_query(['view' => $view, 'date' => $date->format('Y-m-d')]);
}

// ---- A Completed / No-show button was pressed ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::csrfValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['error', 'Your session expired. Please try again.'];
    } else {
        $bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);
        $outcome   = (string) ($_POST['outcome'] ?? '');

        if ($bookingId === false) {
            $_SESSION['flash'] = ['error', 'We could not find that appointment.'];
        } else {
            try {
                $problem = Booking::recordOutcome($bookingId, $stylistId, $outcome);
                $_SESSION['flash'] = ($problem === '')
                    ? ['success', 'The appointment was marked as ' . $outcome . '.']
                    : ['error', $problem];
            } catch (PDOException $ex) {
                error_log('Could not record outcome: ' . $ex->getMessage());
                $_SESSION['flash'] = ['error', 'Sorry, something went wrong on our side. Please try again.'];
            }
        }
    }
    // Go back to the same view (this also stops a refresh from repeating the action).
    header('Location: ' . scheduleUrl($view, $date));
    exit;
}

// ---- Work out the days to show ----
if ($view === 'week') {
    $first = $date->modify('-' . ((int) $date->format('N') - 1) . ' days'); // the Monday of this week
    $days  = 7;
    $step  = '7 days';
    $title = 'Week of ' . $first->format('j M') . ' to ' . $first->modify('+6 days')->format('j M Y');
} else {
    $first = $date;
    $days  = 1;
    $step  = '1 day';
    $title = $date->format('l, j F Y');
}
$previous = $first->modify('-' . $step);
$next     = $first->modify('+' . $step);

$error    = null;
$bookings = [];
try {
    $bookings = Booking::getForStylist($stylistId, $first, $first->modify('+' . $days . ' days'));
} catch (PDOException $ex) {
    error_log('Could not load schedule: ' . $ex->getMessage());
    $error = 'Sorry, we could not load your schedule. Please try again later.';
}

// Group the bookings by day.
$byDay = [];
foreach ($bookings as $b) {
    $byDay[(new DateTimeImmutable($b['start_time']))->format('Y-m-d')][] = $b;
}

// One-time message left by the last action.
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$now = new DateTimeImmutable('now');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Schedule - Executive Grooming Studio</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <script src="assets/js/theme.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
</head>
<body>
    <header class="site-header">
        <h1>Executive Grooming Studio</h1>
        <p>My Schedule</p>
    </header>

    <main class="container">
        <div class="topbar">
            <span>Logged in as <strong><?= e($user['name']) ?></strong> (stylist)</span>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <button type="submit" class="button">Log out</button>
            </form>
        </div>

        <?php if ($flash): ?>
            <p class="<?= $flash[0] === 'success' ? 'success' : 'error' ?>"><?= e($flash[1]) ?></p>
        <?php endif; ?>
        <?php if ($dateProblem): ?>
            <p class="error"><?= e($dateProblem) ?></p>
        <?php endif; ?>

        <p><a class="button" href="stylist_add_booking.php">+ Add a booking</a></p>

        <p class="schedule-nav">
            <a href="<?= e(scheduleUrl('day', $date)) ?>"<?= $view === 'day' ? ' aria-current="page"' : '' ?>>Day view</a> |
            <a href="<?= e(scheduleUrl('week', $date)) ?>"<?= $view === 'week' ? ' aria-current="page"' : '' ?>>Week view</a>
            &nbsp;&nbsp;&nbsp;
            <a href="<?= e(scheduleUrl($view, $previous)) ?>">&larr; Previous</a> |
            <a href="<?= e(scheduleUrl($view, new DateTimeImmutable('today'))) ?>">Today</a> |
            <a href="<?= e(scheduleUrl($view, $next)) ?>">Next &rarr;</a>
        </p>

        <h2><?= e($title) ?></h2>

        <?php if ($error): ?>
            <p class="error"><?= e($error) ?></p>
        <?php else: ?>
            <?php for ($i = 0; $i < $days; $i++): ?>
                <?php $day = $first->modify('+' . $i . ' days'); $list = $byDay[$day->format('Y-m-d')] ?? []; ?>

                <?php if ($view === 'week'): ?>
                    <h3><?= e($day->format('l, j F')) ?></h3>
                <?php endif; ?>

                <?php if (count($list) === 0): ?>
                    <p>No appointments.</p>
                <?php else: ?>
                    <table class="schedule-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Client</th>
                                <th>Service</th>
                                <th>Status</th>
                                <th>Notes</th>
                                <th>Photo</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($list as $b): ?>
                                <?php
                                    $start   = new DateTimeImmutable($b['start_time']);
                                    $end     = new DateTimeImmutable($b['end_time']);
                                    $started = ($start <= $now);
                                ?>
                                <tr>
                                    <td><?= e($start->format('H:i')) ?> - <?= e($end->format('H:i')) ?></td>
                                    <td><?= e($b['client_name']) ?></td>
                                    <td><?= e($b['service_name']) ?></td>
                                    <td><?= e($b['status']) ?></td>
                                    <td><?= $b['notes'] ? nl2br(e($b['notes'])) : '-' ?></td>
                                    <td>
                                        <?php if (!empty($b['inspiration_image'])): ?>
                                            <a href="inspiration_image.php?booking_id=<?= (int) $b['booking_id'] ?>" target="_blank" rel="noopener">
                                                <img class="inspiration-thumb" loading="lazy" src="inspiration_image.php?booking_id=<?= (int) $b['booking_id'] ?>" alt="Inspiration photo from <?= e($b['client_name']) ?>">
                                            </a>
                                        <?php else: ?>-<?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($b['status'] !== 'confirmed'): ?>
                                            -
                                        <?php elseif (!$started): ?>
                                            Not started yet
                                        <?php else: ?>
                                            <?php foreach (['completed' => 'Completed', 'no-show' => 'No-show'] as $value => $label): ?>
                                                <form method="post" action="stylist_schedule.php" class="inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                                    <input type="hidden" name="booking_id" value="<?= (int) $b['booking_id'] ?>">
                                                    <input type="hidden" name="outcome" value="<?= e($value) ?>">
                                                    <input type="hidden" name="view" value="<?= e($view) ?>">
                                                    <input type="hidden" name="date" value="<?= e($date->format('Y-m-d')) ?>">
                                                    <button type="submit" class="button"><?= e($label) ?></button>
                                                </form>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endfor; ?>
        <?php endif; ?>
    </main>
</body>
</html>
