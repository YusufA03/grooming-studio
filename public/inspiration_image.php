<?php
// Shows ONE client's inspiration photo, but only to people who are allowed to see it:
//   - the salon administrator
//   - the stylist the booking belongs to
//   - the client, through their own secret booking link (?token=...)
// The photos live in storage/inspiration, OUTSIDE the public folder, so this page is
// the only way to reach them. Anyone else just gets "Not found".
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Booking.php';
require_once __DIR__ . '/../classes/ImageUpload.php';

function photoNotFound(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Not found.';
    exit;
}

$row = null;

try {
    if (isset($_GET['token'])) {
        // A client, through the secret link in their email.
        $token = $_GET['token'];
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}\z/', $token)) {
            photoNotFound();
        }
        $row = Booking::findInspirationByToken($token);
    } elseif (isset($_GET['booking_id'])) {
        // Staff.
        $user = Auth::user();
        if ($user === null) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo 'Please log in.';
            exit;
        }
        $id = filter_var($_GET['booking_id'], FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            photoNotFound();
        }
        $row = Booking::findInspiration($id);

        if ($row !== null) {
            $allowed = ($user['role'] === 'admin')
                || ($user['role'] === 'stylist' && (int) $row['stylist_id'] === (int) $user['id']);
            if (!$allowed) {
                photoNotFound();   // say "not found", never "not yours", so nothing is given away
            }
        }
    } else {
        photoNotFound();
    }
} catch (Throwable $ex) {
    error_log('Could not look up an inspiration photo: ' . $ex->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Something went wrong.';
    exit;
}

// We have finished with the session: let other page loads carry on while we send the file.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($row === null || empty($row['inspiration_image']) || !preg_match(ImageUpload::NAME_PATTERN, (string) $row['inspiration_image'])) {
    photoNotFound();
}

$path = ImageUpload::inspirationDirectory() . '/' . $row['inspiration_image'];
if (!is_file($path)) {
    photoNotFound();
}

$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime      = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$extension] ?? null;
if ($mime === null) {
    photoNotFound();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="inspiration.' . $extension . '"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, max-age=3600');
readfile($path);
