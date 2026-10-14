<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/admin_nav.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Booking.php';
require_once __DIR__ . '/../classes/Loyalty.php';

// Loyalty points (Phase 1, Section 7): who has how many points, and exchanging points for a
// reward in the salon. Points are earned automatically when a stylist marks an appointment completed.

// Only a logged-in administrator may open this page.
$user = Auth::requireRole('admin');

// ---- A form was sent: exchange points, or correct a balance ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postClient = filter_var($_POST['client_id'] ?? null, FILTER_VALIDATE_INT);
    $action     = inputText($_POST, 'action', '');

    if (!Auth::csrfValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['error', 'Your session expired. Please try again.'];
    } elseif ($postClient === false || $postClient < 1) {
        $_SESSION['flash'] = ['error', 'We could not find that client.'];
    } else {
        try {
            if ($action === 'redeem') {
                $blocks  = filter_var($_POST['blocks'] ?? null, FILTER_VALIDATE_INT);
                $problem = Loyalty::redeem($postClient, $blocks === false ? 0 : $blocks, inputText($_POST, 'note', ''), Auth::actorLabel($user));
                $_SESSION['flash'] = ($problem === '')
                    ? ['success', 'The reward was recorded and the points were taken off.']
                    : ['error', $problem];
            } elseif ($action === 'adjust') {
                $points  = filter_var($_POST['points'] ?? null, FILTER_VALIDATE_INT);
                $problem = Loyalty::adjust($postClient, $points === false ? 0 : $points, inputText($_POST, 'note', ''), Auth::actorLabel($user));
                $_SESSION['flash'] = ($problem === '')
                    ? ['success', 'The balance was corrected.']
                    : ['error', $problem];
            } else {
                $_SESSION['flash'] = ['error', 'That is not something this page can do.'];
            }
        } catch (Throwable $ex) {
            error_log('Loyalty change failed: ' . $ex->getMessage());
            $_SESSION['flash'] = ['error', 'Sorry, something went wrong on our side. Please try again.'];
        }
    }
    // Go back to the client (this also stops a refresh from repeating the action).
    header('Location: admin_loyalty.php' . (($postClient !== false && $postClient >= 1) ? '?client_id=' . (int) $postClient : ''));
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$search   = mb_substr(trim(inputText($_GET, 'q', '')), 0, 100);
$clientId = filter_var($_GET['client_id'] ?? null, FILTER_VALIDATE_INT);

$client = null;
$clients = [];
$ledger = $visits = [];
$balance = 0;
$error = null;

try {
    if (isset($_GET['client_id'])) {
        $client = ($clientId !== false && $clientId >= 1) ? Loyalty::findClient($clientId) : null;
        if ($client === null) {
            http_response_code(404);
            $error = 'We could not find that client.';
        } else {
            $balance = Loyalty::balance((int) $client['client_id']);
            $ledger  = Loyalty::ledger((int) $client['client_id']);
            $visits  = Loyalty::visits((int) $client['client_id']);
        }
    } else {
        $clients = Loyalty::searchClients($search);
    }
} catch (PDOException $ex) {
    error_log('Could not load loyalty page: ' . $ex->getMessage());
    $error = 'Sorry, something went wrong on our side. Please try again later.';
}
$maxBlocks = min(20, intdiv(max(0, $balance), Loyalty::REDEEM_POINTS));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Loyalty Points - Executive Grooming Studio</title>
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

        <?= adminNav('loyalty') ?>

        <?php if ($flash): ?>
            <p class="<?= $flash[0] === 'success' ? 'success' : 'error' ?>"><?= e($flash[1]) ?></p>
        <?php endif; ?>

        <h2>Loyalty points</h2>
        <p class="meta">A client earns 1 point for every R<?= (int) Loyalty::RAND_PER_POINT ?> of a completed appointment.
            Every <?= (int) Loyalty::REDEEM_POINTS ?> points can be exchanged in the salon for R<?= (int) Loyalty::REDEEM_VALUE ?> off
            (or a free add-on of about that value). Clients are recognised by their email address, or by name and phone number when they gave no email.</p>

        <?php if ($error): ?>
            <p class="error"><?= e($error) ?></p>
            <p><a href="admin_loyalty.php">&larr; Back to all clients</a></p>

        <?php elseif ($client !== null): ?>
            <p><a href="admin_loyalty.php">&larr; Back to all clients</a></p>
            <section class="service-card">
                <h3><?= e($client['name']) ?></h3>
                <p><?= ($client['email'] !== null && $client['email'] !== '') ? e($client['email']) : 'no email' ?><?= !empty($client['phone']) ? ' &middot; ' . e($client['phone']) : '' ?></p>
                <p><strong>Balance: <?= (int) $balance ?> points</strong>
                    <?php if ($maxBlocks > 0): ?>(worth <?= (int) ($maxBlocks * Loyalty::REDEEM_VALUE) ?> rand in rewards)<?php endif; ?></p>
            </section>

            <h3>Exchange points for a reward</h3>
            <?php if ($maxBlocks < 1): ?>
                <p>This client needs at least <?= (int) Loyalty::REDEEM_POINTS ?> points to exchange for a reward.</p>
            <?php else: ?>
                <form method="post" action="admin_loyalty.php" class="booking-form"
                      onsubmit="return confirm('Take these points off this client and record the reward?');">
                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="action" value="redeem">
                    <input type="hidden" name="client_id" value="<?= (int) $client['client_id'] ?>">
                    <label for="blocks">Rewards (each is <?= (int) Loyalty::REDEEM_POINTS ?> points = R<?= (int) Loyalty::REDEEM_VALUE ?>)</label>
                    <select name="blocks" id="blocks">
                        <?php for ($i = 1; $i <= $maxBlocks; $i++): ?>
                            <option value="<?= $i ?>"><?= $i ?> (<?= $i * Loyalty::REDEEM_POINTS ?> points, R<?= $i * Loyalty::REDEEM_VALUE ?>)</option>
                        <?php endfor; ?>
                    </select>
                    <label for="redeem_note">What the client received (optional)</label>
                    <input type="text" name="note" id="redeem_note" maxlength="200" placeholder="For example: R50 off a Men's Haircut">
                    <button type="submit" class="button">Record reward</button>
                </form>
            <?php endif; ?>

            <h3>Correct the balance</h3>
            <form method="post" action="admin_loyalty.php" class="booking-form">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="action" value="adjust">
                <input type="hidden" name="client_id" value="<?= (int) $client['client_id'] ?>">
                <label for="points">Points to add (or a minus number to take off)</label>
                <input type="number" name="points" id="points" min="-1000" max="1000" step="1" required>
                <label for="adjust_note">Reason (required)</label>
                <input type="text" name="note" id="adjust_note" maxlength="200" required placeholder="For example: goodwill bonus">
                <button type="submit" class="button">Save correction</button>
            </form>

            <h3>Points history</h3>
            <?php if (count($ledger) === 0): ?>
                <p>No points yet.</p>
            <?php else: ?>
                <table class="schedule-table">
                    <thead><tr><th>When</th><th>Points</th><th>Type</th><th>Note</th><th>By</th></tr></thead>
                    <tbody>
                        <?php foreach ($ledger as $line): ?>
                            <tr>
                                <td><?= e((new DateTimeImmutable($line['created_at']))->format('j M Y, H:i')) ?></td>
                                <td><?= ((int) $line['points'] > 0 ? '+' : '') . (int) $line['points'] ?></td>
                                <td><?= e($line['reason']) ?></td>
                                <td><?= $line['note'] !== null ? e($line['note']) : '-' ?></td>
                                <td><?= e($line['actor']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h3>Visit history</h3>
            <?php if (count($visits) === 0): ?>
                <p>No appointments yet.</p>
            <?php else: ?>
                <table class="schedule-table">
                    <thead><tr><th>When</th><th>Service</th><th>Stylist</th><th>Status</th><th>Price</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($visits as $v): ?>
                            <tr>
                                <td><?= e((new DateTimeImmutable($v['start_time']))->format('j M Y, H:i')) ?></td>
                                <td><?= e($v['service_name']) ?></td>
                                <td><?= e($v['stylist_name']) ?></td>
                                <td><?= e($v['status']) ?></td>
                                <td>R<?= number_format((float) $v['price_charged'], 2) ?></td>
                                <td><a href="admin_booking.php?booking_id=<?= (int) $v['booking_id'] ?>">History</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        <?php else: ?>
            <form method="get" action="admin_loyalty.php" class="filter-form">
                <label for="q">Find a client</label>
                <input type="text" name="q" id="q" value="<?= e($search) ?>" maxlength="100" placeholder="Name, email or phone">
                <button type="submit" class="button">Search</button>
            </form>

            <?php if (count($clients) === 0): ?>
                <p>No clients found.</p>
            <?php else: ?>
                <table class="schedule-table">
                    <thead><tr><th>Client</th><th>Contact</th><th>Completed visits</th><th>Points</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($clients as $c): ?>
                            <tr>
                                <td><?= e($c['name']) ?></td>
                                <td><?= ($c['email'] !== null && $c['email'] !== '') ? e($c['email']) : '(no email)' ?><?= !empty($c['phone']) ? '<br>' . e($c['phone']) : '' ?></td>
                                <td><?= (int) $c['visits'] ?></td>
                                <td><strong><?= (int) $c['balance'] ?></strong></td>
                                <td><a class="button" href="admin_loyalty.php?client_id=<?= (int) $c['client_id'] ?>">Open</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
