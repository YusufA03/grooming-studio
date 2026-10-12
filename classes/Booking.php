<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Stylist.php';

// Represents an appointment, and holds the booking rules.
class Booking
{
    // Appointments can start every 30 minutes.
    private const SLOT_STEP_MINUTES = 30;

    // Returns the start times a stylist is free for a service of the given
    // length on the given day.
    //  - the appointment must finish before the stylist stops work
    //  - it must not overlap an existing booking (cancelled ones do not count)
    //  - it must be in the future
    public static function getAvailableSlots(array $stylist, int $durationMinutes, DateTimeImmutable $date): array
    {
        $window = Stylist::getWorkingWindow($stylist['working_hours'], $date);
        if ($window === null) {
            return []; // stylist does not work on this day
        }
        [$open, $close] = $window;

        // Load this stylist's existing bookings that touch this working day.
        $db   = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT start_time, end_time
             FROM Booking
             WHERE stylist_id = :stylist_id
               AND status <> 'cancelled'
               AND start_time < :close
               AND end_time > :open"
        );
        $stmt->execute([
            ':stylist_id' => $stylist['stylist_id'],
            ':open'       => $open->format('Y-m-d H:i:s'),
            ':close'      => $close->format('Y-m-d H:i:s'),
        ]);
        $booked = $stmt->fetchAll();

        $now      = new DateTimeImmutable('now');
        $duration = new DateInterval('PT' . $durationMinutes . 'M');
        $step     = new DateInterval('PT' . self::SLOT_STEP_MINUTES . 'M');
        $slots    = [];

        for ($start = $open; ; $start = $start->add($step)) {
            $end = $start->add($duration);

            if ($end > $close) {
                break; // would finish after closing time
            }
            if ($start <= $now) {
                continue; // already in the past
            }
            if (self::overlapsAny($start, $end, $booked)) {
                continue; // clashes with an existing booking
            }
            $slots[] = $start;
        }

        return $slots;
    }

    // Two appointments overlap when each one starts before the other ends.
    private static function overlapsAny(DateTimeImmutable $start, DateTimeImmutable $end, array $booked): bool
    {
        foreach ($booked as $row) {
            $bookedStart = new DateTimeImmutable($row['start_time']);
            $bookedEnd   = new DateTimeImmutable($row['end_time']);

            if ($start < $bookedEnd && $end > $bookedStart) {
                return true;
            }
        }
        return false;
    }
}