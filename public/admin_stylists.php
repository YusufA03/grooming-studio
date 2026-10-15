<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/admin_nav.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Service.php';
require_once __DIR__ . '/../classes/Stylist.php';

// The administrator's stylists page: who works here, which services each one performs (ticked, not typed), their working hours
// as blocks (so a lunch break is simply two blocks), and whether they are taking bookings.

// Only a logged-in administrator may open this page.
$user = Auth::requireRole('admin');

// "2 h 30 min" for a service length.
function lengthText(int $minutes): string
{
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return ($h > 0 ? $h . ' h' : '') . ($h > 0 && $m > 0 ? ' ' : '') . ($m > 0 ? $m . ' min' : '');
}

// After hours or services change, tell the administrator if upcoming bookings no longer fit (the bookings are never touched).
function strandedWarning(int $stylistId): ?string
{
    $n = Stylist::strandedBookings($stylistId);
    if ($n === 0) {
        return null;
    }
    return $n . ' upcoming booking' . ($n === 1 ? '' : 's') . ' for this stylist no longer ' . ($n === 1 ? 'fits' : 'fit')
         . ' their services or working hours. The booking' . ($n === 1 ? ' has' : 's have') . ' not been changed: please contact the client'
         . ($n === 1 ? '' : 's') . ' or move the booking' . ($n === 1 ? '' : 's') . '.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = inputText($_POST, 'action', '');
    $stylistId = filter_var($_POST['stylist_id'] ?? null, FILTER_VALIDATE_INT);
    $stylistId = ($stylistId === false) ? 0 : (int) $stylistId;

    if (!Auth::csrfValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['error', 'Your session expired. Please try again.'];
    } else {
        try {
            if ($action === 'add') {
                [$clean, $errors] = Stylist::validate($_POST, null, true);
                [$serviceIds, $serviceError] = Stylist::validateServiceIds($_POST['services'] ?? []);
                $blocks = Stylist::initialBlocks(inputText($_POST, 'first_day'), inputText($_POST, 'last_day'), inputText($_POST, 'open_time'), inputText($_POST, 'close_time'));
                if ($serviceError !== null) { $errors['services'] = $serviceError; }
                if ($blocks === null) { $errors['hours'] = 'Please choose valid starting days, and an opening time earlier than the closing time.'; }
                if ($errors) {
                    $_SESSION['flash'] = ['error', implode(' ', $errors)];
                } else {
                    Stylist::create($clean, $serviceIds, $blocks);
                    $_SESSION['flash'] = ['success', $clean['name'] . ' was added. You can adjust their working hours below.'];
                }
            } elseif ($action === 'update') {
                $existing = Stylist::findForAdmin($stylistId);
                if ($existing === null) {
                    $_SESSION['flash'] = ['error', 'We could not find that stylist.'];
                } else {
                    [$clean, $errors] = Stylist::validate($_POST, $stylistId, false);
                    if ($errors) {
                        $_SESSION['flash'] = ['error', implode(' ', $errors)];
                    } else {
                        Stylist::update($stylistId, $clean);
                        $_SESSION['flash'] = ['success', 'The details were saved.'];
                    }
                }
            } elseif ($action === 'save_services') {
                $problem = Stylist::setServices($stylistId, $_POST['services'] ?? []);
                $_SESSION['flash'] = ($problem === '') ? ['success', 'The services were saved.'] : ['error', $problem];
                if ($problem === '' && ($w = strandedWarning($stylistId)) !== null) { $_SESSION['warn'] = $w; }
            } elseif ($action === 'toggle') {
                if (Stylist::findForAdmin($stylistId) === null) {
                    $_SESSION['flash'] = ['error', 'We could not find that stylist.'];
                } else {
                    $on = (inputText($_POST, 'accepting', '0') === '1');
                    Stylist::setAccepting($stylistId, $on);
                    $_SESSION['flash'] = ['success', $on ? 'This stylist is taking bookings again.' : 'This stylist is no longer offered for new bookings. Bookings already made stay as they are.'];
                }
            } elseif ($action === 'add_block') {
                $day     = filter_var($_POST['day'] ?? null, FILTER_VALIDATE_INT);
                $problem = Stylist::addBlock($stylistId, $day === false ? 0 : (int) $day, inputText($_POST, 'from'), inputText($_POST, 'to'));
                $_SESSION['flash'] = ($problem === '') ? ['success', 'The working hours were added.'] : ['error', $problem];
            } elseif ($action === 'remove_block') {
                $blockId = filter_var($_POST['block_id'] ?? null, FILTER_VALIDATE_INT);
                $problem = ($blockId === false) ? 'We could not find that block.' : Stylist::removeBlock((int) $blockId, $stylistId);
                $_SESSION['flash'] = ($problem === '') ? ['success', 'The working hours were removed.'] : ['error', $problem];
                if ($problem === '' && ($w = strandedWarning($stylistId)) !== null) { $_SESSION['warn'] = $w; }
            } elseif ($action === 'delete') {
                $problem = Stylist::delete($stylistId);
                $_SESSION['flash'] = ($problem === '') ? ['success', 'The stylist was deleted.'] : ['error', $problem];
            } else {
                $_SESSION['flash'] = ['error', 'That is not something this page can do.'];
            }
            if ($action === 'add_block' && ($_SESSION['flash'][0] ?? '') === 'success' && ($w = strandedWarning($stylistId)) !== null) { $_SESSION['warn'] = $w; }
        } catch (Throwable $ex) {
            error_log('Stylist change failed: ' . $ex->getMessage());
            $_SESSION['flash'] = ['error', 'Sorry, something went wrong on our side. Please try again.'];
        }
    }
    header('Location: admin_stylists.php' . ($stylistId > 0 ? '#stylist-' . $stylistId : ''));
    exit;
}

$flash = $_SESSION['flash'] ?? null;
$warn  = $_SESSION['warn'] ?? null;
unset($_SESSION['flash'], $_SESSION['warn']);

$stylists = $services = [];
$error = null;
try {
    $services = Service::getAll();
    $stylists = Stylist::getAllForAdmin();
    foreach ($stylists as &$s) {
        $s['service_ids'] = Stylist::serviceIds((int) $s['stylist_id']);
        $s['blocks']      = Stylist::blocks((int) $s['stylist_id']);
    }
    unset($s);
} catch (PDOException $ex) {
    error_log('Could not load the stylists page: ' . $ex->getMessage());
    $error = 'Sorry, something went wrong on our side. Please try again later.';
}
$dayLabels = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stylists - Executive Grooming Studio</title>
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

        <?= adminNav('stylists') ?>

        <h2>Stylists</h2>
        <?php if ($flash): ?><p class="<?= $flash[0] === 'success' ? 'success' : 'error' ?>"><?= e($flash[1]) ?></p><?php endif; ?>
        <?php if ($warn): ?><p class="warning"><?= e($warn) ?></p><?php endif; ?>
        <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>

        <section class="service-card">
            <h3>Add a stylist</h3>
            <form method="post" action="admin_stylists.php" class="booking-form">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="action" value="add">
                <label for="new_name">Name</label>
                <input type="text" name="name" id="new_name" maxlength="100" required>
                <label for="new_email">Email address (they log in with it)</label>
                <input type="email" name="email" id="new_email" maxlength="150" required>
                <label for="new_password">Password (at least 10 characters)</label>
                <input type="password" name="password" id="new_password" minlength="10" maxlength="72" required autocomplete="new-password">

                <fieldset class="service-checks">
                    <legend>Services this stylist performs (tick at least one)</legend>
                    <?php foreach ($services as $sv): ?>
                        <label class="check"><input type="checkbox" name="services[]" value="<?= (int) $sv['service_id'] ?>">
                            <?= e($sv['name']) ?> <span class="meta">&middot; <?= e(lengthText((int) $sv['duration_minutes'])) ?></span></label>
                    <?php endforeach; ?>
                </fieldset>

                <fieldset class="service-checks">
                    <legend>Starting working hours (you can fine-tune them afterwards)</legend>
                    <label for="first_day">From</label>
                    <select name="first_day" id="first_day"><?php foreach (Stylist::DAYS as $i => $d): ?><option value="<?= e($d) ?>"<?= $d === 'Mon' ? ' selected' : '' ?>><?= e($dayLabels[$i]) ?></option><?php endforeach; ?></select>
                    <label for="last_day">To</label>
                    <select name="last_day" id="last_day"><?php foreach (Stylist::DAYS as $i => $d): ?><option value="<?= e($d) ?>"<?= $d === 'Fri' ? ' selected' : '' ?>><?= e($dayLabels[$i]) ?></option><?php endforeach; ?></select>
                    <label for="open_time">Opening time</label>
                    <input type="time" name="open_time" id="open_time" value="09:00" required>
                    <label for="close_time">Closing time</label>
                    <input type="time" name="close_time" id="close_time" value="17:00" required>
                </fieldset>
                <button type="submit" class="button">Add stylist</button>
            </form>
        </section>

        <?php foreach ($stylists as $s): ?>
            <?php $id = (int) $s['stylist_id']; $taking = ((int) $s['accepting_bookings'] === 1); ?>
            <section class="service-card" id="stylist-<?= $id ?>">
                <div class="appt-top">
                    <h3><?= e($s['name']) ?></h3>
                    <span class="status-badge"><?= $taking ? 'Taking bookings' : 'Not taking bookings' ?></span>
                </div>
                <p class="meta"><?= e($s['email']) ?> &middot; <?= (int) $s['upcoming'] ?> upcoming booking<?= (int) $s['upcoming'] === 1 ? '' : 's' ?></p>

                <form method="post" action="admin_stylists.php" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="stylist_id" value="<?= $id ?>">
                    <input type="hidden" name="accepting" value="<?= $taking ? '0' : '1' ?>">
                    <button type="submit" class="button"><?= $taking ? 'Stop taking bookings' : 'Start taking bookings' ?></button>
                </form>

                <details>
                    <summary>Edit name, email or password</summary>
                    <form method="post" action="admin_stylists.php" class="booking-form">
                        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="stylist_id" value="<?= $id ?>">
                        <label for="name_<?= $id ?>">Name</label>
                        <input type="text" name="name" id="name_<?= $id ?>" maxlength="100" value="<?= e($s['name']) ?>" required>
                        <label for="email_<?= $id ?>">Email address</label>
                        <input type="email" name="email" id="email_<?= $id ?>" maxlength="150" value="<?= e($s['email']) ?>" required>
                        <label for="pw_<?= $id ?>">New password (leave blank to keep the current one)</label>
                        <input type="password" name="password" id="pw_<?= $id ?>" maxlength="72" autocomplete="new-password">
                        <button type="submit" class="button">Save details</button>
                    </form>
                </details>

                <h4>Services this stylist performs</h4>
                <form method="post" action="admin_stylists.php">
                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_services">
                    <input type="hidden" name="stylist_id" value="<?= $id ?>">
                    <div class="service-checks">
                        <?php foreach ($services as $sv): ?>
                            <label class="check"><input type="checkbox" name="services[]" value="<?= (int) $sv['service_id'] ?>"<?= in_array((int) $sv['service_id'], $s['service_ids'], true) ? ' checked' : '' ?>>
                                <?= e($sv['name']) ?> <span class="meta">&middot; <?= e(lengthText((int) $sv['duration_minutes'])) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="button">Save services</button>
                </form>

                <h4>Working hours</h4>
                <p class="meta">An appointment must fit entirely inside one block. To give a lunch break, enter two blocks for the day, for example 09:00 to 13:00 and 14:00 to 17:00: the hour between them becomes unbookable.
                    Now: <?= e(Stylist::describeBlocks($s['blocks'])) ?>.</p>
                <?php if (count($s['blocks']) > 0): ?>
                    <table class="schedule-table">
                        <thead><tr><th>Day</th><th>From</th><th>To</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($s['blocks'] as $b): ?>
                                <tr>
                                    <td><?= e($dayLabels[(int) $b['weekday'] - 1]) ?></td>
                                    <td><?= e($b['start_time']) ?></td>
                                    <td><?= e($b['end_time']) ?></td>
                                    <td>
                                        <form method="post" action="admin_stylists.php">
                                            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                            <input type="hidden" name="action" value="remove_block">
                                            <input type="hidden" name="stylist_id" value="<?= $id ?>">
                                            <input type="hidden" name="block_id" value="<?= (int) $b['block_id'] ?>">
                                            <button type="submit" class="button danger">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <form method="post" action="admin_stylists.php" class="booking-form">
                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="action" value="add_block">
                    <input type="hidden" name="stylist_id" value="<?= $id ?>">
                    <label for="day_<?= $id ?>">Day</label>
                    <select name="day" id="day_<?= $id ?>"><?php foreach ($dayLabels as $i => $label): ?><option value="<?= $i + 1 ?>"><?= e($label) ?></option><?php endforeach; ?></select>
                    <label for="from_<?= $id ?>">From</label>
                    <input type="time" name="from" id="from_<?= $id ?>" value="09:00" required>
                    <label for="to_<?= $id ?>">To</label>
                    <input type="time" name="to" id="to_<?= $id ?>" value="17:00" required>
                    <button type="submit" class="button">Add working hours</button>
                </form>

                <?php if ((int) $s['bookings'] === 0): ?>
                    <form method="post" action="admin_stylists.php" onsubmit="return confirm('Delete this stylist?');">
                        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="stylist_id" value="<?= $id ?>">
                        <button type="submit" class="button danger">Delete this stylist</button>
                    </form>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </main>
</body>
</html>
