<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../classes/Booking.php';
require_once __DIR__ . '/../classes/Mailer.php';

// Cancelling only works from the Cancel button (a form), never from a plain link.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$token = inputText($_POST, 'token', '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    header('Location: index.php');
    exit;
}

$result = 'notchanged';
try {
    if (Booking::cancelByToken($token) === '') {
        $result = 'cancelled';

        // Tell the client. If the email fails the cancellation still stands.
        try {
            $details = Booking::findByToken($token);
            if ($details !== null) {
                Mailer::sendBookingCancellation($details, true);
            }
        } catch (Throwable $mailEx) {
            error_log('Cancellation email step failed: ' . $mailEx->getMessage());
        }
    }
} catch (Throwable $ex) {
    error_log('Could not cancel booking: ' . $ex->getMessage());
}

header('Location: booking_confirmed.php?token=' . $token . '&msg=' . $result);
exit;
