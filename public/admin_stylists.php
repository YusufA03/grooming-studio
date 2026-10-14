<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/admin_nav.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Stylist.php';

// Only a logged-in administrator may open this page.
$user = Auth::requireRole('admin');

$errors  = [];
$editing = null;   // the stylist being edited, if any
$problem = null;   // a problem loading the page
$values  = [
    'name' => '', 'email' => '', 'specialty' => '',
    'hours_off' => '', 'first_day' => 'Mon', 'last_day' => 'Fri', 'open_time' => '09:00', 'close_time' => '17:00',
];

function backToStylists(): void
{
    header('Location: admin_stylists.php');
    exit;
}

// Turns stored working hours into the values the form fields need.
function hoursToFormValues(string $stored): array
{
    $parts = Stylist::parseWorkingHours($stored);
    if ($parts === null) {
        return [];   // unreadable: leave the form's defaults
    }
    if ($parts['off']) {
        return ['hours_off' => '1'];
    }
    return ['hours_off' => '', 'first_day' => $parts['first'], 'last_day' => $parts['last'],
            'open_time' => $parts['open'], 'close_time' => $parts['close']];
}

// ---- A form was sent: add, update or delete ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::csrfValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['error', 'Your session expired. Please try again.'];
        backToStylists();
    }

    $action    = inputText($_POST, 'action', '');
    $stylistId = filter_var($_POST['stylist_id'] ?? null, FILTER_VALIDATE_INT);

    try {
        if ($action === 'add') {
            [$clean, $errors] = Stylist::validate($_POST, null, true);
            if (count($errors) === 0) {
                Stylist::create($clean);
                $_SESSION['flash'] = ['success', $clean['name'] . ' was added. They can log in with the email and password you set.'];
                backToStylists();
            }
            $values = array_merge($values, $_POST);

        } elseif ($action === 'update') {
            $editing = ($stylistId === false) ? null : Stylist::findForAdmin($stylistId);
            if ($editing === null) {
                $_SESSION['flash'] = ['error', 'We could not find that stylist.'];
                backToStylists();
            }
            [$clean, $errors] = Stylist::validate($_POST, $stylistId, false);
            if (count($errors) === 0) {
                Stylist::update($stylistId, $clean);
                $message = $clean['name'] . ' was updated.'
                         . ($clean['password'] !== null ? ' Their password was changed.' : '');

                // Warn if the new hours leave existing bookings outside them.
                $outside = Stylist::countUpcomingOutsideHours($stylistId, $clean['working_hours']);
                if ($outside > 0) {
                    $message .= ' WARNING: ' . $outside . ' upcoming booking' . ($outside === 1 ? '' : 's')
                              . ' now fall' . ($outside === 1 ? 's' : '') . ' outside these working hours. '
                              . 'Review them on the Bookings page.';
                }
                $_SESSION['flash'] = [$outside > 0 ? 'error' : 'success', $message];
                backToStylists();
            }
            $values = array_merge($values, $_POST);

        } elseif ($action === 'delete') {
            $result = ($stylistId === false) ? 'We could not find that stylist.' : Stylist::delete($stylistId);
            $_SESSION['flash'] = ($result === '')
                ? ['success', 'The stylist was deleted.']
                : ['error', $result];
            backToStylists();

        } else {
            $_SESSION['flash'] = ['error', 'That action was not recognised.'];
            backToStylists();
        }
    } catch (PDOException $ex) {
        error_log('Stylist management failed: ' . $ex->getMessage());
        $_SESSION['flash'] = ['error', 'Sorry, something went wrong on our side. Please try again.'];
        backToStylists();
    }
}

// ---- Show the page ----
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$stylists = [];
try {
    $stylists = Stylist::getAllForAdmin();

    // ?edit=3 opens the edit form for stylist 3
    if ($editing === null && isset($_GET['edit'])) {
        $wanted  = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
        $editing = ($wanted === false) ? null : Stylist::findForAdmin($wanted);
        if ($editing === null) {
            $flash = ['error', 'We could not find that stylist.'];
        } else {
            $values = array_merge($values, [
                'name'      => $editing['name'],
                'email'     => $editing['email'],
                'specialty' => (string) $editing['specialty'],
            ], hoursToFormValues($editing['working_hours']));
        }
    }
} catch (PDOException $ex) {
    error_log('Could not load stylists: ' . $ex->getMessage());
    $problem = 'Sorry, something went wrong on our side. Please try again later.';
}

$dayLabels = ['Mon' => 'Monday', 'Tue' => 'Tuesday', 'Wed' => 'Wednesday', 'Thu' => 'Thursday',
              'Fri' => 'Friday', 'Sat' => 'Saturday', 'Sun' => 'Sunday'];
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
        <p>Administrator - Stylists</p>
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

        <?php if ($flash): ?>
            <p class="<?= $flash[0] === 'success' ? 'success' : 'error' ?>"><?= e($flash[1]) ?></p>
        <?php endif; ?>
        <?php if ($problem): ?>
            <p class="error"><?= e($problem) ?></p>
        <?php else: ?>

        <h2>Stylists</h2>
        <?php if (count($stylists) === 0): ?>
            <p>There are no stylists yet. Add the first one below.</p>
        <?php else: ?>
            <table class="schedule-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email (login)</th>
                        <th>Specialty</th>
                        <th>Working hours</th>
                        <th>Upcoming bookings</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stylists as $s): ?>
                        <tr>
                            <td><?= e($s['name']) ?></td>
                            <td><?= e($s['email']) ?></td>
                            <td><?= $s['specialty'] ? e($s['specialty']) : '-' ?></td>
                            <td><?= e(Stylist::describeWorkingHours($s['working_hours'])) ?></td>
                            <td><?= (int) $s['upcoming'] ?></td>
                            <td>
                                <a href="admin_stylists.php?edit=<?= (int) $s['stylist_id'] ?>">Edit</a>
                                <?php if ((int) $s['bookings'] === 0): ?>
                                    <form method="post" action="admin_stylists.php" class="inline-form"
                                          onsubmit="return confirm('Delete this stylist? This cannot be undone.');">
                                        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="stylist_id" value="<?= (int) $s['stylist_id'] ?>">
                                        <button type="submit" class="button">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2><?= $editing ? 'Edit stylist' : 'Add a stylist' ?></h2>
        <form method="post" action="admin_stylists.php" class="booking-form">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'add' ?>">
            <?php if ($editing): ?>
                <input type="hidden" name="stylist_id" value="<?= (int) $editing['stylist_id'] ?>">
            <?php endif; ?>

            <label for="name">Name</label>
            <input type="text" name="name" id="name" maxlength="100" required value="<?= e((string) $values['name']) ?>">
            <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>

            <label for="email">Email address (their login)</label>
            <input type="email" name="email" id="email" maxlength="150" required value="<?= e((string) $values['email']) ?>">
            <?php if (isset($errors['email'])): ?><p class="field-error"><?= e($errors['email']) ?></p><?php endif; ?>

            <label for="specialty">Specialty (optional)</label>
            <input type="text" name="specialty" id="specialty" maxlength="100" value="<?= e((string) $values['specialty']) ?>">
            <?php if (isset($errors['specialty'])): ?><p class="field-error"><?= e($errors['specialty']) ?></p><?php endif; ?>

            <label>Working hours</label>
            <p class="hours-row">
                <select name="first_day" aria-label="First working day">
                    <?php foreach ($dayLabels as $abbr => $label): ?>
                        <option value="<?= e($abbr) ?>"<?= ($values['first_day'] === $abbr) ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                to
                <select name="last_day" aria-label="Last working day">
                    <?php foreach ($dayLabels as $abbr => $label): ?>
                        <option value="<?= e($abbr) ?>"<?= ($values['last_day'] === $abbr) ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                from
                <input type="time" name="open_time" aria-label="Opening time" value="<?= e((string) $values['open_time']) ?>">
                until
                <input type="time" name="close_time" aria-label="Closing time" value="<?= e((string) $values['close_time']) ?>">
            </p>
            <p>
                <label>
                    <input type="checkbox" name="hours_off" value="1"<?= !empty($values['hours_off']) ? ' checked' : '' ?>>
                    Not taking bookings (ignores the days and times above)
                </label>
            </p>
            <?php if (isset($errors['working_hours'])): ?><p class="field-error"><?= e($errors['working_hours']) ?></p><?php endif; ?>

            <label for="password"><?= $editing ? 'New password (leave empty to keep the current one)' : 'Password (at least 10 characters)' ?></label>
            <input type="password" name="password" id="password" autocomplete="new-password"<?= $editing ? '' : ' required' ?>>
            <?php if (isset($errors['password'])): ?><p class="field-error"><?= e($errors['password']) ?></p><?php endif; ?>

            <button type="submit" class="button"><?= $editing ? 'Save changes' : 'Add stylist' ?></button>
            <?php if ($editing): ?><a href="admin_stylists.php">Cancel</a><?php endif; ?>
        </form>

        <?php endif; ?>
    </main>
</body>
</html>
