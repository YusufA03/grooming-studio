<?php
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Booking.php';
require_once __DIR__ . '/../classes/Mailer.php';
require_once __DIR__ . '/../classes/Loyalty.php';

// What staff do to a booking from the stylist page and the administrator dashboard. Both pages use these two functions,
// so they follow exactly the same rules.
//   $onlyStylistId: a stylist passes their own id (they may only touch their own bookings); an administrator passes null.
// Each returns [true|false, message to show].

// Cancels an upcoming booking, records who did it, and emails the client (the cancellation still stands if the email fails).
function staffCancel(int $bookingId, array $user, ?int $onlyStylistId = null): array
{
    $problem = Booking::cancel($bookingId, Auth::actorLabel($user), $onlyStylistId);
    if ($problem !== '') {
        return [false, $problem];
    }

    $emailed = false;
    $noEmail = false;
    try {
        $details = Booking::findDetails($bookingId);
        if ($details !== null && empty($details['client_email'])) {
            $noEmail = true;
        } elseif ($details !== null) {
            $emailed = Mailer::sendBookingCancellation($details);
        }
    } catch (Throwable $mailEx) {
        error_log('Cancellation email step failed: ' . $mailEx->getMessage());
    }
    return [true, 'The appointment was cancelled. '
        . ($noEmail ? 'This client has no email address, so please tell them yourself.'
            : ($emailed ? 'The client has been emailed.' : 'The client could NOT be emailed, so please tell them yourself.'))];
}

// Marks a booking Completed (the client earns loyalty points) or No-show. Only once the appointment has started, and only once.
function staffOutcome(int $bookingId, array $user, string $outcome, ?int $onlyStylistId = null): array
{
    $problem = Booking::recordOutcome($bookingId, $onlyStylistId, $outcome, Auth::actorLabel($user));
    if ($problem !== '') {
        return [false, $problem];
    }
    $earned = ($outcome === 'completed') ? Loyalty::earnedForBooking($bookingId) : 0;
    return [true, 'The appointment was marked as ' . $outcome . '.' . ($earned > 0 ? ' The client earned ' . $earned . ' loyalty points.' : '')];
}
