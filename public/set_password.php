<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/account_bar.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Account.php';

// Opened from the emailed one-time link: the person chooses a password and is logged in.

$secret = inputText($_GET, 'token', inputText($_POST, 'token', ''));
$error  = null;
$link   = null;

try {
    $link = Account::findValid($secret);

    if ($link !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = inputText($_POST, 'password', '');
        $confirm  = inputText($_POST, 'confirm', '');

        if (!Auth::csrfValid($_POST['csrf_token'] ?? null)) {
            $error = 'Your session expired. Please try again.';
        } elseif ($password !== $confirm) {
            $error = 'The two passwords are not the same.';
        } else {
            $result = Account::setPassword($secret, $password);
            if ($result['error'] !== null) {
                $error = $result['error'];
            } elseif (Auth::loginClientById((int) $result['client_id'])) {
                header('Location: ' . homePageFor(Auth::user()['role']));
                exit;
            } else {
                $error = 'Your password was saved, but we could not log you in. Please log in.';
            }
        }
    }
} catch (PDOException $ex) {
    error_log('Choose-password page failed: ' . $ex->getMessage());
    $error = 'Sorry, something went wrong on our side. Please try again later.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose your password - Executive Grooming Studio</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <script src="assets/js/theme.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
</head>
<body>
    <header class="site-header">
        <h1>Executive Grooming Studio</h1>
        <p>Your account</p>
    </header>
    <?= accountBar() ?>

    <main class="container">
        <h2>Choose your password</h2>
        <?php if ($link === null): ?>
            <p class="error">This link has expired or has already been used.</p>
            <p><a href="register.php">Ask for a new link</a> &middot; <a href="login.php">Log in</a></p>
        <?php else: ?>
            <p>For <strong><?= e($link['email']) ?></strong>. Choose a password of <?= (int) Account::MIN_PASSWORD ?> to <?= (int) Account::MAX_PASSWORD ?> characters.</p>
            <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
            <form method="post" action="set_password.php" class="booking-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="token" value="<?= e($secret) ?>">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" minlength="<?= (int) Account::MIN_PASSWORD ?>" maxlength="<?= (int) Account::MAX_PASSWORD ?>" required autocomplete="new-password">
                <label for="confirm">Type it again</label>
                <input type="password" name="confirm" id="confirm" minlength="<?= (int) Account::MIN_PASSWORD ?>" maxlength="<?= (int) Account::MAX_PASSWORD ?>" required autocomplete="new-password">
                <button type="submit" class="button">Save password and log in</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
