<?php
// Small helpers shared by every page.

// All times in the system use South African time.
require_once __DIR__ . '/timezone.php';

// Makes text safe to show on a web page (stops injected HTML/scripts).
function e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
