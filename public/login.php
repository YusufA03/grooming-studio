<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../classes/Auth.php';

// Where each type of staff member lands after logging in.
function homePageFor(string $role): string
{
    return ($role === 'admin') ? 'admin_dashboard.php' : 'stylist_schedule.php';
}

// Already logged in? Go straight to your own page.
$user = Auth::user();
if ($user !== null) {
    header('Location: ' . homePageFor($user['role']));
    exit;
}

$error = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim(inputText($_POST, 'email', ''));
    $password = inputText($_POST, 'password', '');

    if (!Auth::csrfValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        try {
            if (Auth::attempt($email, $password)) {
                header('Location: ' . homePageFor(Auth::user()['role']));
                exit;
            }
            // The same message whether the email or the password was wrong.
            $error = 'Incorrect email or password.';
        } catch (PDOException $ex) {
            error_log('Login failed to run: ' . $ex->getMessage());
            $error = 'Sorry, something went wrong on our side. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff Login - Executive Grooming Studio</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <script src="assets/js/theme.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
</head>
<body>
    <header class="site-header">
        <h1>Executive Grooming Studio</h1>
        <p>Staff login</p>
    </header>

    <main class="container">
        <p><a href="index.php">&larr; Back to the home page</a></p>

        <?php if (isset($_GET['loggedout'])): ?>
            <p class="success">You have been logged out.</p>
        <?php endif; ?>
        <?php if ($error): ?>
            <p class="error"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="post" action="login.php" class="booking-form">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">

            <label for="email">Email address</label>
            <input type="email" name="email" id="email" maxlength="150" required
                   autocomplete="username" value="<?= e($email) ?>">

            <label for="password">Password</label>
            <input type="password" name="password" id="password" required
                   autocomplete="current-password">

            <button type="submit" class="button">Log in</button>
        </form>
    </main>
</body>
</html>
