<?php
// The small menu shown at the top of every administrator page.
function adminNav(string $current): string
{
    $links = [
        'bookings'   => ['admin_dashboard.php',     'Bookings'],
        'addbooking' => ['stylist_add_booking.php', 'Add booking'],
        'services'   => ['admin_services.php',      'Services'],
        'stylists'   => ['admin_stylists.php',      'Stylists'],
    ];

    $parts = [];
    foreach ($links as $key => [$url, $label]) {
        $parts[] = ($key === $current)
            ? '<strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong>'
            : '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }
    return '<p class="admin-nav">' . implode(' | ', $parts) . '</p>';
}
