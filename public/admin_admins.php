<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/admin_nav.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/AdminAccess.php';

// Giving and taking away admin access from the website. (tools/set_password.php stays as a backup way to make one.)

// Only a logged-in administrator may open this page.
$user = Auth::requireRole('admin');

$name = $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = inputText($_POST, 'action', '');
    if (!Auth::csrfValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['error', 'Your session expired. Please try again.'];
    } else {
        try {
            if ($action === 'add') {
                $result = AdminAccess::grant(inputText($_POST, 'name', ''), inputText($_POST, 'email', ''));
                if ($result['error'] !== null) {
                    $_SESSION['flash'] = ['error', $result['error']];
                } else {
                    $_SESSION['flash'] = ['success', $result['message']];
                    if ($result['link'] !== null) { $_SESSION['new_admin_link'] = $result['link']; }
                }
            } elseif ($action === 'remove') {
                $id = filter_var($_POST['admin_id'] ?? null, FILTER_VALIDATE_INT);
                $problem = ($id === false || $id < 1) ? 'We could not find that administrator.' : AdminAccess::revoke($id, (int) $user['id']);
                $_SESSION['flash'] = ($problem === '') ? ['success', 'Admin access was removed.'] : ['error', $problem];
            } else {
                $_SESSION['flash'] = ['error', 'That is not something this page can do.'];
            }
        } catch (Throwable $ex) {
            error_log('Admin access change failed: ' . $ex->getMessage());
            $_SESSION['flash'] = ['error', 'Sorry, something went wrong on our side. Please try again.'];
        }
    }
    header('Location: admin_admins.php');
    exit;
}

$flash   = $_SESSION['flash'] ?? null;
$newLink = $_SESSION['new_admin_link'] ?? null;
unset($_SESSION['flash'], $_SESSION['new_admin_link']);

$admins = [];
$error  = null;
try {
    $admins = AdminAccess::listAdmins();
} catch (PDOException $ex) {
    error_log('Could not list administrators: ' . $ex->getMessage());
    $error = 'Sorry, something went wrong on our side. Please try again later.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administrators - Executive Grooming Studio</title>
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

        <?= adminNav('admins') ?>

        <h2>Administrators</h2>
        <?php if ($flash): ?><p class="<?= $flash[0] === 'success' ? 'success' : 'error' ?>"><?= e($flash[1]) ?></p><?php endif; ?>
        <?php if ($newLink !== null): ?>
            <div class="service-card">
                <p><strong>One-time link for the new administrator</strong> (shown only once):</p>
                <p><code><?= e($newLink) ?></code></p>
            </div>
        <?php endif; ?>

        <h3>Give someone admin access</h3>
        <p>If the email address already has a client account, that account gets admin access and keeps its own password.
            Otherwise a new account is created, and a one-time link to choose a password is emailed to them and shown once at the top of this page.
            Stylists are added under Stylists instead.</p>
        <form method="post" action="admin_admins.php" class="booking-form">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
            <input type="hidden" name="action" value="add">
            <label for="name">Name</label>
            <input type="text" name="name" id="name" maxlength="100" required>
            <label for="email">Email address</label>
            <input type="email" name="email" id="email" maxlength="150" required>
            <button type="submit" class="button">Add administrator</button>
        </form>

        <h3>Current administrators</h3>
        <?php if ($error): ?>
            <p class="error"><?= e($error) ?></p>
        <?php else: ?>
            <table class="schedule-table">
                <thead><tr><th>Name</th><th>Email</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($admins as $a): ?>
                        <?php $isYou = ((int) $a['admin_id'] === (int) $user['id']); ?>
                        <tr>
                            <td><?= e($a['name']) ?><?= $isYou ? ' (you)' : '' ?></td>
                            <td><?= e($a['email']) ?></td>
                            <td><?php
                                if ($a['own_password'] !== null && (int) $a['own_password'] === 1) { echo 'Active'; }
                                elseif ((int) $a['client_active'] === 1) { echo 'Active'; }
                                else { echo 'Waiting for them to choose a password'; }
                            ?></td>
                            <td>
                                <?php if (!$isYou): ?>
                                    <form method="post" action="admin_admins.php" onsubmit="return confirm('Remove admin access for this person?');">
                                        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="admin_id" value="<?= (int) $a['admin_id'] ?>">
                                        <button type="submit" class="button danger">Remove admin access</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>
</body>
</html>
