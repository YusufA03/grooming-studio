<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Stylist.php';
require_once __DIR__ . '/Client.php';

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

    // Saves a new booking and returns its secret token, or null if the time
    // is no longer free.
    //
    // The stylist's row is LOCKED while we check and save. If two clients
    // click the same time at the same moment, the second one has to wait,
    // then sees the time is taken. This stops double bookings.
    public static function create(
        array $stylist,
        array $service,
        DateTimeImmutable $start,
        string $clientName,
        string $clientEmail,
        ?string $clientPhone,
        ?string $notes
    ): ?string {
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            // 1. Lock this stylist (and read their current working hours).
            $lock = $db->prepare(
                'SELECT stylist_id, working_hours FROM Stylist WHERE stylist_id = :id FOR UPDATE'
            );
            $lock->execute([':id' => $stylist['stylist_id']]);
            $current = $lock->fetch();
            if ($current === false) {
                $db->rollBack();
                return null;
            }

            // 2. Check the time is still free (hours, future, no overlap).
            $duration = (int) $service['duration_minutes'];
            $isFree   = false;
            foreach (self::getAvailableSlots($current, $duration, $start) as $slot) {
                if ($slot == $start) {
                    $isFree = true;
                    break;
                }
            }
            if (!$isFree) {
                $db->rollBack();
                return null;
            }

            // 3. Save the client and the booking.
            $end      = $start->add(new DateInterval('PT' . $duration . 'M'));
            $clientId = Client::findOrCreate($clientName, $clientEmail, $clientPhone);
            $token    = bin2hex(random_bytes(32)); // 64 random characters

            $insert = $db->prepare(
                "INSERT INTO Booking
                    (client_id, stylist_id, service_id, start_time, end_time, status, notes, manage_token)
                 VALUES
                    (:client_id, :stylist_id, :service_id, :start_time, :end_time, 'confirmed', :notes, :token)"
            );
            $insert->execute([
                ':client_id'  => $clientId,
                ':stylist_id' => $stylist['stylist_id'],
                ':service_id' => $service['service_id'],
                ':start_time' => $start->format('Y-m-d H:i:s'),
                ':end_time'   => $end->format('Y-m-d H:i:s'),
                ':notes'      => $notes,
                ':token'      => $token,
            ]);

            $db->commit();
            return $token;
        } catch (Throwable $ex) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $ex;
        }
    }

    // Finds a booking (with its service, stylist and client details) by its
    // secret token. Returns null if there is no such booking.
    public static function findByToken(string $token): ?array
    {
        $db   = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT b.booking_id, b.start_time, b.end_time, b.status, b.notes,
                    s.name AS service_name, s.price,
                    st.name AS stylist_name,
                    c.name AS client_name, c.email AS client_email
             FROM Booking b
             JOIN Service s  ON s.service_id  = b.service_id
             JOIN Stylist st ON st.stylist_id = b.stylist_id
             JOIN Client c   ON c.client_id   = b.client_id
             WHERE b.manage_token = :token'
        );
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    // Returns one stylist's bookings that START from $from up to (but not
    // including) $to, earliest first. Used for the stylist's schedule.
    public static function getForStylist(int $stylistId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $db   = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT b.booking_id, b.start_time, b.end_time, b.status, b.notes,
                    s.name AS service_name,
                    c.name AS client_name
             FROM Booking b
             JOIN Service s ON s.service_id = b.service_id
             JOIN Client c  ON c.client_id  = b.client_id
             WHERE b.stylist_id = :stylist_id
               AND b.start_time >= :from_time
               AND b.start_time <  :to_time
             ORDER BY b.start_time, b.booking_id'
        );
        $stmt->execute([
            ':stylist_id' => $stylistId,
            ':from_time'  => $from->format('Y-m-d H:i:s'),
            ':to_time'    => $to->format('Y-m-d H:i:s'),
        ]);
        return $stmt->fetchAll();
    }

    // A stylist records how an appointment ended: 'completed' or 'no-show'.
    // Rules:
    //  - it must be THIS stylist's own appointment
    //  - it must still be 'confirmed' (completed / no-show are final)
    //  - it must have already started
    // Returns '' when it worked, otherwise a message explaining why not.
    public static function recordOutcome(int $bookingId, int $stylistId, string $outcome): string
    {
        if (!in_array($outcome, ['completed', 'no-show'], true)) {
            return 'That is not a valid choice.';
        }

        $db     = Database::getConnection();
        $now    = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $update = $db->prepare(
            "UPDATE Booking
             SET status = :outcome
             WHERE booking_id = :id
               AND stylist_id = :stylist_id
               AND status = 'confirmed'
               AND start_time <= :now"
        );
        $update->execute([
            ':outcome'    => $outcome,
            ':id'         => $bookingId,
            ':stylist_id' => $stylistId,
            ':now'        => $now,
        ]);

        if ($update->rowCount() === 1) {
            return '';
        }

        // It did not change anything, so find out why (still only this stylist's own booking).
        $check = $db->prepare('SELECT status FROM Booking WHERE booking_id = :id AND stylist_id = :stylist_id');
        $check->execute([':id' => $bookingId, ':stylist_id' => $stylistId]);
        $status = $check->fetchColumn();

        if ($status === false) {
            return 'We could not find that appointment.';
        }
        if ($status !== 'confirmed') {
            return 'That appointment has already been marked as ' . $status . '.';
        }
        return 'You can only mark an appointment after it has started.';
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
