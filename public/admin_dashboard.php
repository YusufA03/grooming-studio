<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/admin_nav.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Booking.php';
require_once __DIR__ . '/../classes/Stylist.php';
require_once __DIR__ . '/../classes/Mailer.php';

// Only a logged-in administrator may open this page.
$user = Auth::requireRole('admin');

const BOOKINGS_PER_PAGE = 25;
$allStatuses = ['confirmed', 'completed', 'no-show', 'cancelled'];

// Reads a date typed into a filter. Returns null (and notes a problem) if it is not a real date.
function readDate(array $input, string $key, string $label, array &$problems): ?DateTimeImmutable
{
    $text = trim((string) ($input[$key] ?? ''));
    if ($text === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
    if ($date === false || $date->format('Y-m-d') !== $text) {
        $problems[] = 'The "' . $label . '" date was not valid, so it was ignored.';
        return null;
    }
    return $date;
}

// ---- Read and check the filters ----
$input = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && count($_GET) === 0) {
    $input['from'] = (new DateTimeImmutable('today'))->format('Y-m-d'); // first visit: show upcoming bookings
}

$problems = [];
$stylists = [];
$loadError = null;
try {
    $stylists = Stylist::getAll();
} catch (PDOException $ex) {
    error_log('Could not load stylists: ' . $ex->getMessage());
    $loadError = 'Sorry, something went wrong on our side. Please try again later.';
}

$from = readDate($input, 'from', 'from', $problems);
$to   = readDate($input, 'to', 'to', $problems);
if ($from !== null && $to !== null && $from > $to) {
    [$from, $to] = [$to, $from];
    $problems[] = 'The "from" date was after the "to" date, so the two were swapped.';
}

$stylistId   = null;
$stylistText = trim((string) ($input['stylist_id'] ?? ''));
if ($stylistText !== '') {
    $wanted = filter_var($stylistText, FILTER_VALIDATE_INT);
    $known  = array_map('intval', array_column($stylists, 'stylist_id'));
    if ($wanted !== false && in_array($wanted, $known, true)) {
        $stylistId = $wanted;
    } else {
        $problems[] = 'That stylist was not recognised, so it was ignored.';
    }
}

$status     = null;
$statusText = trim((string) ($input['status'] ?? ''));
if ($statusText !== '') {
    if (in_array($statusText, $allStatuses, true)) {
        $status = $statusText;
    } else {
        $problems[] = 'That status was not recognised, so it was ignored.';
    }
}

$q = mb_substr(trim((string) ($input['q'] ?? '')), 0, 100);

$page = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT);
if ($page === false || $page < 1) {
    $page = 1;
}

// The filters, as they are used for the query and for the links.
$filters = ['from' => $from, 'to' => $to, 'stylist_id' => $stylistId, 'status' => $status, 'q' => $q];
$linkParams = [
    'from'       => $from ? $from->format('Y-m-d') : '', // always present, so "no start date" is remembered
    'to'         => $to ? $to->format('Y-m-d') : '',
    'stylist_id' => $stylistId ?? '',
    'status'     => $status ?? '',
    'q'          => $q,
];
function dashboardUrl(array $linkParams, int $page): string
{
    return 'admin_dashboard.php?' . http_build_query($linkParams + ['page' => $page]);
}

// ---- The Cancel button was pressed ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::csrfValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['error', 'Your session expired. Please try again.'];
    } else {
        $bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);

        if ($bookingId === false) {
            $_SESSION['flash'] = ['error', 'We could not find that appointment.'];
        } else {
            try {
                $problem = Booking::cancel($bookingId);
                if ($problem !== '') {
                    $_SESSION['flash'] = ['error', $problem];
                } else {
                    // Tell the client. If the email fails the cancellation still stands.
                    $emailed = false;
                    try {
                        $details = Booking::findDetails($bookingId);
                        if ($details !== null) {
                            $emailed = Mailer::sendBookingCancellation($details);
                        }
                    } catch (Throwable $mailEx) {
                        error_log('Cancellation email step failed: ' . $mailEx->getMessage());
                    }
                    $_SESSION['flash'] = ['success', 'The appointment was cancelled. '
                        . ($emailed ? 'The client has been emailed.' : 'The client could NOT be emailed, so please tell them yourself.')];
                }
            } catch (PDOException $ex) {
                error_log('Could not cancel booking: ' . $ex->getMessage());
                $_SESSION['flash'] = ['error', 'Sorry, something went wrong on our side. Please try again.'];
            }
        }
    }
    header('Location: ' . dashboardUrl($linkParams, $page)); // back to the same filters and page
    exit;
}

// ---- Find the bookings ----
$bookings = [];
$total    = 0;
$pages    = 1;
if ($loadError === null) {
    try {
        $total = Booking::countSearch($filters);
        $pages = max(1, (int) ceil($total / BOOKINGS_PER_PAGE));
        $page  = min($page, $pages);
        $bookings = Booking::search($filters, BOOKINGS_PER_PAGE, ($page - 1) * BOOKINGS_PER_PAGE);
    } catch (PDOException $ex) {
        error_log('Could not search bookings: ' . $ex->getMessage());
        $loadError = 'Sorry, something went wrong on our side. Please try again later.';
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$now   = new DateTimeImmutable('now');
$first = ($total === 0) ? 0 : ($page - 1) * BOOKINGS_PER_PAGE + 1;
$last  = ($page - 1) * BOOKINGS_PER_PAGE + count($bookings);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administrator Dashboard - Executive Grooming Studio</title>
    <link rel="stylesheet" href="assets/css/style.css">
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

        <?php if ($flash): ?>
            <p class="<?= $flash[0] === 'success' ? 'success' : 'error' ?>"><?= e($flash[1]) ?></p>
        <?php endif; ?>
        <?php foreach ($problems as $problemText): ?>
            <p class="error"><?= e($problemText) ?></p>
        <?php endforeach; ?>

        <h2>All bookings</h2>

        <form method="get" action="admin_dashboard.php" class="filter-form">
            <label for="from">From</label>
            <input type="date" name="from" id="from" value="<?= e($linkParams['from']) ?>">

            <label for="to">To</label>
            <input type="date" name="to" id="to" value="<?= e($linkParams['to']) ?>">

            <label for="stylist_id">Stylist</label>
            <select name="stylist_id" id="stylist_id">
                <option value="">All stylists</option>
                <?php foreach ($stylists as $s): ?>
                    <option value="<?= (int) $s['stylist_id'] ?>"<?= ($stylistId === (int) $s['stylist_id']) ? ' selected' : '' ?>>
                        <?= e($s['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="status">Status</label>
            <select name="status" id="status">
                <option value="">All statuses</option>
                <?php foreach ($allStatuses as $st): ?>
                    <option value="<?= e($st) ?>"<?= ($status === $st) ? ' selected' : '' ?>><?= e($st) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="q">Client name or email</label>
            <input type="text" name="q" id="q" maxlength="100" value="<?= e($q) ?>">

            <button type="submit" class="button">Filter</button>
            <a href="admin_dashboard.php">Reset</a>
        </form>

        <?php if ($loadError): ?>
            <p class="error"><?= e($loadError) ?></p>
        <?php elseif ($total === 0): ?>
            <p>No bookings match these filters.</p>
        <?php else: ?>
            <p>Showing <?= (int) $first ?> to <?= (int) $last ?> of <?= (int) $total ?> bookings.</p>

            <table class="schedule-table">
                <thead>
                    <tr>
                        <th>Date and time</th>
                        <th>Client</th>
                        <th>Stylist</th>
                        <th>Service</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $b): ?>
                        <?php
                            $start    = new DateTimeImmutable($b['start_time']);
                            $end      = new DateTimeImmutable($b['end_time']);
                            $canCancel = ($b['status'] === 'confirmed' && $start > $now);
                        ?>
                        <tr>
                            <td><?= e($start->format('D j M Y')) ?><br><?= e($start->format('H:i')) ?> - <?= e($end->format('H:i')) ?></td>
                            <td>
                                <?= e($b['client_name']) ?><br>
                                <?= e($b['client_email']) ?>
                                <?php if ($b['client_phone']): ?><br><?= e($b['client_phone']) ?><?php endif; ?>
                            </td>
                            <td><?= e($b['stylist_name']) ?></td>
                            <td><?= e($b['service_name']) ?></td>
                            <td><?= e($b['status']) ?></td>
                            <td><?= $b['notes'] ? nl2br(e($b['notes'])) : '-' ?></td>
                            <td>
                                <?php if ($canCancel): ?>
                                    <form method="post" action="admin_dashboard.php" class="inline-form"
                                          onsubmit="return confirm('Cancel this appointment and email the client?');">
                                        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                        <input type="hidden" name="booking_id" value="<?= (int) $b['booking_id'] ?>">
                                        <?php foreach ($linkParams as $name => $value): ?>
                                            <input type="hidden" name="<?= e($name) ?>" value="<?= e((string) $value) ?>">
                                        <?php endforeach; ?>
                                        <input type="hidden" name="page" value="<?= (int) $page ?>">
                                        <button type="submit" class="button">Cancel</button>
                                    </form>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= e(dashboardUrl($linkParams, $page - 1)) ?>">&larr; Previous</a>
                <?php endif; ?>
                Page <?= (int) $page ?> of <?= (int) $pages ?>
                <?php if ($page < $pages): ?>
                    <a href="<?= e(dashboardUrl($linkParams, $page + 1)) ?>">Next &rarr;</a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </main>
</body>
</html>
