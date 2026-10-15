<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../classes/Auth.php';

// The small bar of account links under the page title on the public pages:
// "Log in / Create account" for visitors, and "My appointments / Sign out" for people who are logged in.
function accountBar(): string
{
    $user = Auth::user();
    if ($user === null) {
        return '<nav class="account-bar" aria-label="Account"><a href="login.php">Log in</a><a href="register.php">Create account</a></nav>';
    }
    $links = '';
    if (!empty($user['client_id'])) {
        $links .= '<a href="my_appointments.php">My appointments</a>';
    }
    if ($user['role'] === 'admin') {
        $links .= '<a href="admin_dashboard.php">Admin</a>';
    } elseif ($user['role'] === 'stylist') {
        $links .= '<a href="stylist_schedule.php">My schedule</a>';
    }
    $links .= '<form method="post" action="logout.php">'
            . '<input type="hidden" name="csrf_token" value="' . e(Auth::csrfToken()) . '">'
            . '<button type="submit" class="link-button">Sign out</button></form>';
    return '<nav class="account-bar" aria-label="Account"><span class="account-name">' . e((string) $user['name']) . '</span>' . $links . '</nav>';
}
