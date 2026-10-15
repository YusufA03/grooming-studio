<?php
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/Loyalty.php';
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
    // $ignoreBookingId lets a booking that is being moved ignore its own
    // current slot (otherwise it would clash with itself).
    public static function getAvailableSlots(array $stylist, int $durationMinutes, DateTimeImmutable $date, ?int $ignoreBookingId = null): array
    {
        $stylistId = (int) $stylist['stylist_id'];

        // A stylist who has stopped taking bookings has no free times (bookings already made stay as they are).
        $accepting = array_key_exists('accepting_bookings', $stylist)
            ? ((int) $stylist['accepting_bookings'] === 1)
            : Stylist::isAccepting($stylistId);
        if (!$accepting) {
            return [];
        }

        // Working hours are BLOCKS (for example 09:00-13:00 and 14:00-17:00, so the lunch hour is not bookable).
        $blocks = Stylist::blocksOn($stylistId, $date);
        if (count($blocks) === 0) {
            return []; // stylist does not work on this day
        }
        $firstOpen = $blocks[0][0];
        $lastClose = $blocks[count($blocks) - 1][1];

        // Load this stylist's existing bookings that touch this working day.
        $db   = Database::getConnection();
        $sql    = "SELECT start_time, end_time
                   FROM Booking
                   WHERE stylist_id = :stylist_id
                     AND status <> 'cancelled'
                     AND start_time < :close
                     AND end_time > :open";
        $params = [
            ':stylist_id' => $stylistId,
            ':open'       => $firstOpen->format('Y-m-d H:i:s'),
            ':close'      => $lastClose->format('Y-m-d H:i:s'),
        ];
        if ($ignoreBookingId !== null) {
            $sql .= ' AND booking_id <> :ignore_id';
            $params[':ignore_id'] = $ignoreBookingId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $booked = $stmt->fetchAll();

        $now      = new DateTimeImmutable('now');
        $duration = new DateInterval('PT' . $durationMinutes . 'M');
        $step     = new DateInterval('PT' . self::SLOT_STEP_MINUTES . 'M');
        $slots    = [];

        // An appointment must finish before its block ends, so it can never run into a break.
        foreach ($blocks as [$open, $close]) {
            for ($start = $open; ; $start = $start->add($step)) {
                $end = $start->add($duration);

                if ($end > $close) {
                    break; // would finish after this block ends
                }
                if ($start <= $now) {
                    continue; // already in the past
                }
                if (self::overlapsAny($start, $end, $booked)) {
                    continue; // clashes with an existing booking
                }
                $slots[] = $start;
            }
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
        ?string $clientEmail,
        ?string $clientPhone,
        ?string $notes,
        ?string $inspirationImage = null,
        ?string $allergies = null,
        string $actor = 'Client (online)'
    ): ?string {
        $db = Database::getConnection();

        // Every read inside this transaction must see the very latest saved data.
        $db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $db->beginTransaction();

        try {
            // 1. Lock this stylist (and read their current working hours).
            $lock = $db->prepare(
                'SELECT stylist_id, accepting_bookings FROM Stylist WHERE stylist_id = :id FOR UPDATE'
            );
            $lock->execute([':id' => $stylist['stylist_id']]);
            $current = $lock->fetch();
            if ($current === false) {
                $db->rollBack();
                return null;
            }

            // The stylist must perform this service. This is checked again here, inside the transaction, so an edited
            // address cannot book a stylist for something they do not do.
            $skill = $db->prepare('SELECT COUNT(*) FROM StylistService WHERE stylist_id = :st AND service_id = :sv');
            $skill->execute([':st' => $stylist['stylist_id'], ':sv' => $service['service_id']]);
            if ((int) $skill->fetchColumn() === 0) {
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
                    (client_id, stylist_id, service_id, start_time, end_time, status, notes, allergies, price_charged, inspiration_image, manage_token)
                 VALUES
                    (:client_id, :stylist_id, :service_id, :start_time, :end_time, 'confirmed', :notes, :allergies, :price, :image, :token)"
            );
            $insert->execute([
                ':client_id'  => $clientId,
                ':stylist_id' => $stylist['stylist_id'],
                ':service_id' => $service['service_id'],
                ':start_time' => $start->format('Y-m-d H:i:s'),
                ':end_time'   => $end->format('Y-m-d H:i:s'),
                ':notes'      => $notes,
                ':allergies'  => $allergies,
                ':price'      => $service['price'],
                ':image'      => $inspirationImage,
                ':token'      => $token,
            ]);

            // The audit line is saved in the same transaction as the booking itself.
            AuditLog::record(
                $db,
                (int) $db->lastInsertId(),
                'created',
                $actor,
                $service['name'] . ' with ' . $stylist['name'] . ' at ' . $start->format('Y-m-d H:i')
            );

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
            'SELECT b.booking_id, b.client_id, b.stylist_id, b.service_id, b.start_time, b.end_time, b.status, b.notes, b.allergies, b.price_charged, b.inspiration_image,
                    s.name AS service_name, s.price, s.duration_minutes,
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
            'SELECT b.booking_id, b.start_time, b.end_time, b.status, b.notes, b.allergies, b.price_charged, b.inspiration_image,
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
    // $stylistId limits it to that stylist's own bookings (a stylist); null means any booking (an administrator).
    public static function recordOutcome(int $bookingId, ?int $stylistId, string $outcome, string $actor = 'Stylist'): string
    {
        if (!in_array($outcome, ['completed', 'no-show'], true)) {
            return 'That is not a valid choice.';
        }

        $db  = Database::getConnection();
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        // The new status, its audit line and any loyalty points are saved together, or not at all.
        $db->beginTransaction();
        try {
            // Completed can be recorded at any time (the staff page asks "not started yet, are you sure?" first, and the
            // history notes it). A NO-SHOW only makes sense once the appointment has started.
            $sql    = "UPDATE Booking SET status = :outcome WHERE booking_id = :id AND status = 'confirmed'"
                    . ($outcome === 'no-show' ? ' AND start_time <= :now' : '')
                    . ($stylistId === null ? '' : ' AND stylist_id = :stylist_id');
            $params = [':outcome' => $outcome, ':id' => $bookingId];
            if ($outcome === 'no-show') {
                $params[':now'] = $now;
            }
            if ($stylistId !== null) {
                $params[':stylist_id'] = $stylistId;
            }
            $update = $db->prepare($sql);
            $update->execute($params);

            if ($update->rowCount() === 1) {
                $when  = $db->prepare('SELECT start_time FROM Booking WHERE booking_id = :id');
                $when->execute([':id' => $bookingId]);
                $early = ($outcome === 'completed' && new DateTimeImmutable((string) $when->fetchColumn()) > new DateTimeImmutable($now));
                AuditLog::record($db, $bookingId, $outcome, $actor, $early ? 'Marked completed before the appointment started' : null);
                if ($outcome === 'completed') {
                    Loyalty::awardForBooking($db, $bookingId);
                }
                $db->commit();
                return '';
            }
            $db->rollBack();
        } catch (Throwable $ex) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $ex;
        }

        // It did not change anything, so find out why (still only this stylist's own booking).
        $check = $db->prepare('SELECT status FROM Booking WHERE booking_id = :id' . ($stylistId === null ? '' : ' AND stylist_id = :stylist_id'));
        $check->execute($stylistId === null ? [':id' => $bookingId] : [':id' => $bookingId, ':stylist_id' => $stylistId]);
        $status = $check->fetchColumn();

        if ($status === false) {
            return 'We could not find that appointment.';
        }
        if ($status !== 'confirmed') {
            return 'That appointment has already been marked as ' . $status . '.';
        }
        return 'A no-show can only be recorded after the appointment has started.';
    }

    // Finds bookings for the administrator's dashboard, earliest first.
    // $filters may hold any of:
    //   from       (DateTimeImmutable)  bookings on or after this day
    //   to         (DateTimeImmutable)  bookings on or before this day
    //   stylist_id (int)
    //   status     (string)             confirmed, completed, no-show or cancelled
    //   q          (string)             text to find in the client's name or email
    public static function search(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = self::buildFilter($filters);

        $sql = 'SELECT b.booking_id, b.start_time, b.end_time, b.status, b.notes, b.allergies, b.price_charged, b.inspiration_image,
                       s.name  AS service_name,
                       st.name AS stylist_name,
                       c.name  AS client_name,
                       c.email AS client_email,
                       c.phone AS client_phone
                FROM Booking b
                JOIN Service s  ON s.service_id  = b.service_id
                JOIN Stylist st ON st.stylist_id = b.stylist_id
                JOIN Client c   ON c.client_id   = b.client_id'
             . $where
             . ' ORDER BY b.start_time, b.booking_id'
             . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // How many bookings match the same filters (used for the page numbers).
    public static function countSearch(array $filters): int
    {
        [$where, $params] = self::buildFilter($filters);

        $sql = 'SELECT COUNT(*)
                FROM Booking b
                JOIN Client c ON c.client_id = b.client_id'
             . $where;

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    // Finds one booking, with its service, stylist and client details, by id.
    // For the administrator only. Returns null if there is no such booking.
    public static function findDetails(int $bookingId): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT b.booking_id, b.client_id, b.stylist_id, b.manage_token, b.start_time, b.end_time, b.status, b.notes, b.allergies, b.price_charged, b.inspiration_image,
                    s.name AS service_name, s.price, s.duration_minutes,
                    st.name AS stylist_name,
                    c.name AS client_name, c.email AS client_email, c.phone AS client_phone
             FROM Booking b
             JOIN Service s  ON s.service_id  = b.service_id
             JOIN Stylist st ON st.stylist_id = b.stylist_id
             JOIN Client c   ON c.client_id   = b.client_id
             WHERE b.booking_id = :id'
        );
        $stmt->execute([':id' => $bookingId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    // For the photo page: who owns booking number $bookingId, and what is its photo?
    // Returns null if there is no such booking.
    public static function findInspiration(int $bookingId): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT booking_id, stylist_id, inspiration_image FROM Booking WHERE booking_id = :id'
        );
        $stmt->execute([':id' => $bookingId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    // The same, found through the client's secret link.
    public static function findInspirationByToken(string $token): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT booking_id, stylist_id, inspiration_image FROM Booking WHERE manage_token = :token'
        );
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    // Cancels a booking. The time becomes free again straight away, because
    // the availability check ignores cancelled bookings.
    // Rules: it must still be 'confirmed' and must not have started yet.
    // Returns '' when it worked, otherwise a message explaining why not.
    // $onlyStylistId limits it to that stylist's own bookings (a stylist cancelling); null means any booking.
    public static function cancel(int $bookingId, string $actor = 'System', ?int $onlyStylistId = null): string
    {
        $db  = Database::getConnection();
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        // The cancellation and its audit line are saved together, or not at all.
        $db->beginTransaction();
        try {
            $before = $db->prepare('SELECT start_time, stylist_id FROM Booking WHERE booking_id = :id');
            $before->execute([':id' => $bookingId]);
            $row   = $before->fetch();
            $wasAt = $row === false ? false : $row['start_time'];
            if ($onlyStylistId !== null && ($row === false || (int) $row['stylist_id'] !== $onlyStylistId)) {
                $db->rollBack();
                return 'We could not find that appointment.';   // not this stylist's booking: the same answer as "no such booking"
            }

            $update = $db->prepare(
                "UPDATE Booking
                 SET status = 'cancelled'
                 WHERE booking_id = :id
                   AND status = 'confirmed'
                   AND start_time > :now"
            );
            $update->execute([':id' => $bookingId, ':now' => $now]);

            if ($update->rowCount() === 1) {
                AuditLog::record($db, $bookingId, 'cancelled', $actor, 'The appointment was at ' . substr((string) $wasAt, 0, 16));
                $db->commit();
                return '';
            }
            $db->rollBack();
        } catch (Throwable $ex) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $ex;
        }

        // It did not change anything, so find out why.
        $check = $db->prepare('SELECT status FROM Booking WHERE booking_id = :id');
        $check->execute([':id' => $bookingId]);
        $status = $check->fetchColumn();

        if ($status === false) {
            return 'We could not find that appointment.';
        }
        if ($status === 'cancelled') {
            return 'That appointment has already been cancelled.';
        }
        if ($status !== 'confirmed') {
            return 'That appointment is already marked as ' . $status . ', so it cannot be cancelled.';
        }
        return 'Only upcoming appointments can be cancelled. Use Completed or No-show for one that has started.';
    }

    // Clients can cancel or move their own booking online up to this many
    // minutes before it starts. Staff are not limited by this.
    // (A salon policy: change the number to suit.)
    public const CLIENT_CHANGE_CUTOFF_MINUTES = 120;

    // Can the CLIENT still change this booking? Takes a booking as returned by
    // findByToken(). Returns '' if yes, otherwise a message explaining why not.
    public static function clientChangeProblem(array $booking): string
    {
        if ($booking['status'] === 'cancelled') {
            return 'This booking has been cancelled.';
        }
        if ($booking['status'] !== 'confirmed') {
            return 'This booking is marked as ' . $booking['status'] . ', so it can no longer be changed.';
        }

        $latest = (new DateTimeImmutable($booking['start_time']))
            ->modify('-' . self::CLIENT_CHANGE_CUTOFF_MINUTES . ' minutes');
        if (new DateTimeImmutable('now') > $latest) {
            $minutes = self::CLIENT_CHANGE_CUTOFF_MINUTES;
            if ($minutes % 60 === 0) {
                $hours = intdiv($minutes, 60);
                $limit = $hours . ($hours === 1 ? ' hour' : ' hours');
            } else {
                $limit = $minutes . ' minutes';
            }
            return 'Bookings can be changed online up to ' . $limit . ' before the appointment. Please contact the salon.';
        }
        return '';
    }

    // The client cancels their own booking, using the secret token from their email.
    // Returns '' when it worked, otherwise a message explaining why not.
    public static function cancelByToken(string $token, string $actor = 'Client (private link)'): string
    {
        $booking = self::findByToken($token);
        if ($booking === null) {
            return 'We could not find that booking.';
        }

        $problem = self::clientChangeProblem($booking);
        if ($problem !== '') {
            return $problem;
        }
        return self::cancel((int) $booking['booking_id'], $actor);
    }

    // Moves a booking to a new start time, with the SAME stylist and service.
    // The end time is recalculated from the service's duration, availability is
    // checked again under the same lock that protects new bookings, and the
    // reminder is re-armed for the new time. The booking stays 'confirmed'.
    // $clientRules is true for a client (who is stopped by the cut-off time).
    // Returns '' when it worked, otherwise a message explaining why not.
    public static function reschedule(int $bookingId, DateTimeImmutable $newStart, bool $clientRules, string $actor = 'System'): string
    {
        $db = Database::getConnection();

        // Whose stylist is it? We must know this to lock the right diary. It is
        // read BEFORE the transaction starts, because a read inside a
        // transaction can freeze an out-of-date view of the data.
        $first = $db->prepare('SELECT stylist_id FROM Booking WHERE booking_id = :id');
        $first->execute([':id' => $bookingId]);
        $stylistId = $first->fetchColumn();
        if ($stylistId === false) {
            return 'We could not find that booking.';
        }

        // Every read inside this transaction must see the very latest saved data.
        $db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $db->beginTransaction();

        try {
            // Lock the stylist, exactly as a new booking does.
            $lock = $db->prepare('SELECT stylist_id, accepting_bookings FROM Stylist WHERE stylist_id = :id FOR UPDATE');
            $lock->execute([':id' => $stylistId]);
            $stylist = $lock->fetch();
            if ($stylist === false) {
                $db->rollBack();
                return 'We could not find that booking.';
            }

            // Read the booking again, now that nobody else can change this stylist's diary.
            $read = $db->prepare(
                'SELECT b.booking_id, b.service_id, b.start_time, b.status, s.duration_minutes
                 FROM Booking b
                 JOIN Service s ON s.service_id = b.service_id
                 WHERE b.booking_id = :id
                 FOR UPDATE'
            );
            $read->execute([':id' => $bookingId]);
            $booking = $read->fetch();
            if ($booking === false) {
                $db->rollBack();
                return 'We could not find that booking.';
            }

            if ($booking['status'] !== 'confirmed') {
                $db->rollBack();
                return 'Only a confirmed booking can be moved. This one is ' . $booking['status'] . '.';
            }
            $currentStart = new DateTimeImmutable($booking['start_time']);
            if ($currentStart <= new DateTimeImmutable('now')) {
                $db->rollBack();
                return 'This appointment has already started, so it cannot be moved.';
            }
            if ($clientRules) {
                $problem = self::clientChangeProblem(['status' => 'confirmed', 'start_time' => $booking['start_time']]);
                if ($problem !== '') {
                    $db->rollBack();
                    return $problem;
                }
            }
            if ($newStart == $currentStart) {
                $db->rollBack();
                return 'That is already the time of this appointment.';
            }

            if ((int) $stylist['accepting_bookings'] !== 1) {
                $db->rollBack();
                return 'This stylist is not taking bookings at the moment, so the appointment cannot be moved to a new time.';
            }
            $skill = $db->prepare('SELECT COUNT(*) FROM StylistService WHERE stylist_id = :st AND service_id = :sv');
            $skill->execute([':st' => $stylistId, ':sv' => $booking['service_id']]);
            if ((int) $skill->fetchColumn() === 0) {
                $db->rollBack();
                return 'This stylist no longer performs that service, so the appointment cannot be moved to a new time.';
            }

            // Is the new time free? (the booking's own current slot does not count against it)
            $duration = (int) $booking['duration_minutes'];
            $isFree   = false;
            foreach (self::getAvailableSlots($stylist, $duration, $newStart, $bookingId) as $slot) {
                if ($slot == $newStart) {
                    $isFree = true;
                    break;
                }
            }
            if (!$isFree) {
                $db->rollBack();
                return 'Sorry, that time is not available. Please choose another time.';
            }

            $newEnd = $newStart->add(new DateInterval('PT' . $duration . 'M'));
            $db->prepare(
                "UPDATE Booking
                 SET start_time = :start_time, end_time = :end_time, reminder_sent = FALSE
                 WHERE booking_id = :id AND status = 'confirmed'"
            )->execute([
                ':start_time' => $newStart->format('Y-m-d H:i:s'),
                ':end_time'   => $newEnd->format('Y-m-d H:i:s'),
                ':id'         => $bookingId,
            ]);

            AuditLog::record(
                $db,
                $bookingId,
                'rescheduled',
                $actor,
                $currentStart->format('Y-m-d H:i') . ' moved to ' . $newStart->format('Y-m-d H:i')
            );

            $db->commit();
            return '';
        } catch (Throwable $ex) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $ex;
        }
    }

    // Turns the dashboard's filters into the WHERE part of a query,
    // always using placeholders so typed text can never change the query.
    private static function buildFilter(array $filters): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['from'])) {
            $where[] = 'b.start_time >= :from_time';
            $params[':from_time'] = $filters['from']->format('Y-m-d') . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'b.start_time < :to_time';
            $params[':to_time'] = $filters['to']->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        if (!empty($filters['stylist_id'])) {
            $where[] = 'b.stylist_id = :stylist_id';
            $params[':stylist_id'] = (int) $filters['stylist_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'b.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            // % and _ are wildcards in LIKE, so make them ordinary characters.
            $like = '%' . addcslashes($filters['q'], '\\%_') . '%';
            $where[] = '(c.name LIKE :q_name OR c.email LIKE :q_email)';
            $params[':q_name']  = $like;
            $params[':q_email'] = $like;
        }

        return [count($where) ? ' WHERE ' . implode(' AND ', $where) : '', $params];
    }

    // Two appointments overlap when each one starts before the other ends.
    // A signed-in client's own appointments, newest first (for the "My appointments" page).
    public static function forClient(int $clientId): array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT b.booking_id, b.start_time, b.end_time, b.status, b.price_charged, b.manage_token,
                    s.name AS service_name, st.name AS stylist_name
             FROM Booking b
             JOIN Service s  ON s.service_id  = b.service_id
             JOIN Stylist st ON st.stylist_id = b.stylist_id
             WHERE b.client_id = :id
             ORDER BY b.start_time DESC
             LIMIT 200'
        );
        $stmt->execute([':id' => $clientId]);
        return $stmt->fetchAll();
    }

    // Smart scheduling (Phase 4, Section 4.6): the SOONEST free time for a service.
    // $preferred is the stylist the client chose, or null for "any available stylist".
    // The preferred stylist's soonest time wins. If that stylist has nothing free within
    // $horizonDays, the soonest time with any other stylist is suggested instead.
    // Returns ['stylist' => array, 'start' => DateTimeImmutable, 'preferred' => bool], or null
    // when nobody has a free time in that period. 'preferred' is false only for the fallback.
    public static function suggestSlot(array $stylists, ?array $preferred, int $durationMinutes, int $horizonDays = 21): ?array
    {
        $today = new DateTimeImmutable('today');

        if ($preferred !== null) {
            $own = self::soonestFor($preferred, $durationMinutes, $today, $horizonDays);
            if ($own !== null) {
                return ['stylist' => $preferred, 'start' => $own, 'preferred' => true];
            }
        }

        $best = null;
        foreach ($stylists as $other) {
            if ($preferred !== null && (int) $other['stylist_id'] === (int) $preferred['stylist_id']) {
                continue;
            }
            $time = self::soonestFor($other, $durationMinutes, $today, $horizonDays);
            if ($time !== null && ($best === null || $time < $best['start'])) {
                $best = ['stylist' => $other, 'start' => $time, 'preferred' => ($preferred === null)];
            }
        }
        return $best;
    }

    // The first free start time for one stylist, looking day by day. Null if none within $horizonDays.
    private static function soonestFor(array $stylist, int $durationMinutes, DateTimeImmutable $firstDay, int $horizonDays): ?DateTimeImmutable
    {
        for ($i = 0; $i < $horizonDays; $i++) {
            $slots = self::getAvailableSlots($stylist, $durationMinutes, $firstDay->add(new DateInterval('P' . $i . 'D')));
            if (count($slots) > 0) {
                return $slots[0];
            }
        }
        return null;
    }

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
