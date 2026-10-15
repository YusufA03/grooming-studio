<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/account_bar.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Account.php';

// Optional client accounts. A client does not need one to book. This page also serves anyone who has forgotten
// their password: either way we email a one-time link, and opening it lets the person choose a password.

$user = Auth::user();
if ($user !== null) {
    header('Location: ' . homePageFor($user['role']));
    exit;
}

$name  = '';
$email = '';
$error = null;
$sent  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim(inputText($_POST, 'name', ''));
    $email = trim(inputText($_POST, 'email', ''));

    if (!Auth::csrfValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($name === '' || mb_strlen($name) > 100) {
        $error = 'Please enter your name (up to 100 characters).';
    } elseif ($email === '' || mb_strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            Account::requestLink($name, $email);
        } catch (Throwable $ex) {
            error_log('Could not start an account: ' . $ex->getMessage());
        }
        // The same answer whether or not the email already has an account.
        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create an account - Executive Grooming Studio</title>
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
        <h2>Create an account</h2>
        <p>An account is <strong>optional</strong>. You can always book without one. With an account you can see your
            upcoming and past appointments and your loyalty points in one place.</p>

        <?php if ($sent): ?>
            <p class="success">If that email address can have an account, we have sent it a link. Open the link within
                <?= (int) Account::LINK_HOURS ?> hours to choose your password. Please also check your spam folder.</p>
        <?php else: ?>
            <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
            <form method="post" action="register.php" class="booking-form">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <label for="name">Your name</label>
                <input type="text" name="name" id="name" value="<?= e($name) ?>" maxlength="100" required>
                <label for="email">Email address</label>
                <input type="email" name="email" id="email" value="<?= e($email) ?>" maxlength="150" required>
                <p class="field-hint">We email you a link to choose your password. Forgotten your password? Enter your email here
                    and we will send you a link to choose a new one.</p>
                <button type="submit" class="button">Email me a link</button>
            </form>
        <?php endif; ?>

        <p><a href="login.php">Already have an account? Log in</a> &middot; <a href="index.php">Back to the home page</a></p>
    </main>
</body>
</html>
