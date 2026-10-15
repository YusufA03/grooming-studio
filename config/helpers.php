<?php
// Small helpers shared by every page.

// All times in the system use South African time.
require_once __DIR__ . '/timezone.php';

// Makes text safe to show on a web page (stops injected HTML/scripts).
function e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Reads one value from the address (GET) or a form (POST) as plain text. A value that is not plain
// text (for example ?name[]=x) counts as missing, so an odd request can never cause a PHP warning.
function inputText(array $source, string $key, string $default = ''): string
{
    $value = $source[$key] ?? null;
    return is_string($value) ? $value : $default;
}

// Where each type of person lands after logging in.
function homePageFor(string $role): string
{
    if ($role === 'admin') { return 'admin_dashboard.php'; }
    if ($role === 'client') { return 'my_appointments.php'; }
    return 'stylist_schedule.php';
}

// A short reference a client can quote on the phone, for example EGS-00042.
function bookingReference(int $bookingId): string
{
    return sprintf('EGS-%05d', $bookingId);
}
