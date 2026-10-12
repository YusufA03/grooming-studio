<?php
require_once __DIR__ . '/../classes/Auth.php';

// Logging out only works from the Log out button (a form with the secret token).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::csrfValid($_POST['csrf_token'] ?? null)) {
    Auth::logout();
    header('Location: login.php?loggedout=1');
    exit;
}

header('Location: index.php');
exit;
