<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Booking.php';
require_once __DIR__ . '/Mailer.php';

// Sends the "your appointment is coming up" emails.
//
// Something must run sendDue() every few minutes (the host's scheduler, or a
// free outside timer that visits cron_reminders.php). Each run sends a
// reminder to every confirmed booking that starts within the next hour and
// has not been reminded yet. A reminder therefore arrives between an hour
// before the appointment and (an hour minus one run interval) before it.
class Reminder
{
    // How far ahead of the appointment the reminder is sent.
    public const LEAD_MINUTES = 60;

    // One run never sends more than this many, and never starts a new email
    // after this many seconds, so a slow mail server cannot make it hang.
    private const MAX_PER_RUN = 30;
    private const TIME_BUDGET_SECONDS = 25;

    // Sends every reminder that is due. Returns how it went:
    //   due     how many bookings were due when this run looked
    //   sent    reminders emailed
    //   failed  reminders that could not be emailed (they are tried again next run)
    //   left    due bookings not attempted because the time budget ran out
    public static function sendDue(): array
    {
        $db      = Database::getConnection();
        $began   = microtime(true);
        $now     = new DateTimeImmutable('now');
        $horizon = $now->modify('+' . self::LEAD_MINUTES . ' minutes');

        $find = $db->prepare(
            "SELECT b.booking_id, b.manage_token
             FROM Booking b
             JOIN Client c ON c.client_id = b.client_id
             WHERE b.status = 'confirmed'
               AND b.reminder_sent = FALSE
               AND c.email IS NOT NULL
               AND b.start_time > :now
               AND b.start_time <= :horizon
             ORDER BY b.start_time, b.booking_id
             LIMIT " . self::MAX_PER_RUN
        );
        $find->execute([
            ':now'     => $now->format('Y-m-d H:i:s'),
            ':horizon' => $horizon->format('Y-m-d H:i:s'),
        ]);
        $due = $find->fetchAll();

        $result = ['due' => count($due), 'sent' => 0, 'failed' => 0, 'left' => 0];

        foreach ($due as $index => $row) {
            if (microtime(true) - $began > self::TIME_BUDGET_SECONDS) {
                $result['left'] = count($due) - $index;
                break;
            }

            // Claim this reminder first. If two runs overlap, only one of them
            // can change reminder_sent from FALSE to TRUE, so only one sends.
            $claim = $db->prepare(
                "UPDATE Booking SET reminder_sent = TRUE
                 WHERE booking_id = :id AND reminder_sent = FALSE AND status = 'confirmed'"
            );
            $claim->execute([':id' => $row['booking_id']]);
            if ($claim->rowCount() !== 1) {
                continue; // someone else got there first, or it was cancelled meanwhile
            }

            $sent = false;
            try {
                $details = Booking::findByToken($row['manage_token']);
                if ($details !== null) {
                    $sent = Mailer::sendBookingReminder($details, $row['manage_token']);
                }
            } catch (Throwable $ex) {
                error_log('Reminder failed for booking ' . $row['booking_id'] . ': ' . $ex->getMessage());
            }

            if ($sent) {
                $result['sent']++;
            } else {
                // Hand the reminder back so the next run tries again.
                $db->prepare('UPDATE Booking SET reminder_sent = FALSE WHERE booking_id = :id')
                   ->execute([':id' => $row['booking_id']]);
                $result['failed']++;
            }
        }

        return $result;
    }
}
