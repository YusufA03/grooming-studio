<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Loyalty.php';
require_once __DIR__ . '/Stylist.php';

// The administrator's reports (Phase 1, Section 11; Phase 3, Section 3.5): busy periods, popular
// services and stylist performance. Every method takes the first and last DAY of the period and
// counts the bookings that START on those days.
class Report
{
    // Rates: no-show rate = no-shows / (completed + no-shows). Cancellation rate = cancelled / all bookings.
    public static function summary(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'confirmed'), 0) AS confirmed,
                    COALESCE(SUM(status = 'completed'), 0) AS completed,
                    COALESCE(SUM(status = 'no-show'), 0)   AS no_show,
                    COALESCE(SUM(status = 'cancelled'), 0) AS cancelled,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN price_charged END), 0) AS revenue
             FROM Booking
             WHERE start_time >= :from_time AND start_time < :to_time"
        );
        $stmt->execute(self::range($from, $to));
        $r = $stmt->fetch();

        $finished = (int) $r['completed'] + (int) $r['no_show'];
        return [
            'total'             => (int) $r['total'],
            'confirmed'         => (int) $r['confirmed'],
            'completed'         => (int) $r['completed'],
            'no_show'           => (int) $r['no_show'],
            'cancelled'         => (int) $r['cancelled'],
            'revenue'           => (float) $r['revenue'],
            'no_show_rate'      => $finished > 0 ? (int) $r['no_show'] / $finished : null,
            'cancellation_rate' => (int) $r['total'] > 0 ? (int) $r['cancelled'] / (int) $r['total'] : null,
        ];
    }

    // Bookings (not cancelled) for each day of the week: index 0 = Monday ... 6 = Sunday.
    public static function byWeekday(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT WEEKDAY(start_time) AS d, COUNT(*) AS n FROM Booking
             WHERE status <> 'cancelled' AND start_time >= :from_time AND start_time < :to_time
             GROUP BY d"
        );
        $stmt->execute(self::range($from, $to));
        $out = array_fill(0, 7, 0);
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['d']] = (int) $row['n'];
        }
        return $out;
    }

    // Bookings (not cancelled) by the hour they start: [hour => count], for the hours 8 to 19 plus any other hour that has bookings.
    public static function byHour(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT HOUR(start_time) AS h, COUNT(*) AS n FROM Booking
             WHERE status <> 'cancelled' AND start_time >= :from_time AND start_time < :to_time
             GROUP BY h"
        );
        $stmt->execute(self::range($from, $to));
        $out = [];
        for ($h = 8; $h <= 19; $h++) {
            $out[$h] = 0;
        }
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['h']] = (int) $row['n'];
        }
        ksort($out);
        return $out;
    }

    // Popular services: bookings (not cancelled) and the money from completed ones, most booked first.
    public static function byService(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT s.service_id, s.name,
                    COUNT(b.booking_id) AS bookings,
                    COALESCE(SUM(b.status = 'completed'), 0) AS completed,
                    COALESCE(SUM(CASE WHEN b.status = 'completed' THEN b.price_charged END), 0) AS revenue
             FROM Service s
             LEFT JOIN Booking b ON b.service_id = s.service_id
                  AND b.status <> 'cancelled' AND b.start_time >= :from_time AND b.start_time < :to_time
             GROUP BY s.service_id, s.name
             ORDER BY bookings DESC, s.name"
        );
        $stmt->execute(self::range($from, $to));
        return $stmt->fetchAll();
    }

    // Stylist performance: every stylist, even one with no bookings in the period.
    public static function byStylist(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT st.stylist_id, st.name,
                    COUNT(b.booking_id) AS total,
                    COALESCE(SUM(b.status = 'confirmed'), 0) AS confirmed,
                    COALESCE(SUM(b.status = 'completed'), 0) AS completed,
                    COALESCE(SUM(b.status = 'no-show'), 0)   AS no_show,
                    COALESCE(SUM(b.status = 'cancelled'), 0) AS cancelled,
                    COALESCE(SUM(CASE WHEN b.status = 'completed' THEN b.price_charged END), 0) AS revenue,
                    COALESCE(SUM(CASE WHEN b.status IN ('confirmed', 'completed')
                                      THEN TIMESTAMPDIFF(MINUTE, b.start_time, b.end_time) END), 0) AS booked_minutes
             FROM Stylist st
             LEFT JOIN Booking b ON b.stylist_id = st.stylist_id
                  AND b.start_time >= :from_time AND b.start_time < :to_time
             GROUP BY st.stylist_id, st.name
             ORDER BY completed DESC, st.name"
        );
        $stmt->execute(self::range($from, $to));
        return $stmt->fetchAll();
    }

    // ====================================================================== the five management reports

    // 1. Bookings register: every appointment in the period (optionally one stylist, one status, or a search by client name or
    // reference such as EGS-00042). Returns at most 2,001 rows so a page never becomes enormous; the caller notes when it is cut.
    public static function register(DateTimeImmutable $from, DateTimeImmutable $to, ?int $stylistId, ?string $status, string $search): array
    {
        $sql = "SELECT b.booking_id, b.start_time, b.end_time, b.status, b.price_charged,
                       c.name AS client_name, s.name AS service_name, st.name AS stylist_name
                FROM Booking b
                JOIN Client  c  ON c.client_id   = b.client_id
                JOIN Service s  ON s.service_id  = b.service_id
                JOIN Stylist st ON st.stylist_id = b.stylist_id
                WHERE b.start_time >= :from_time AND b.start_time < :to_time";
        $params = self::range($from, $to);
        if ($stylistId !== null) {
            $sql .= ' AND b.stylist_id = :stylist_id';
            $params[':stylist_id'] = $stylistId;
        }
        if ($status !== null && in_array($status, ['confirmed', 'completed', 'no-show', 'cancelled'], true)) {
            $sql .= ' AND b.status = :status';
            $params[':status'] = $status;
        }
        $search = trim($search);
        if ($search !== '') {
            if (preg_match('/^EGS-?0*(\d+)$/i', $search, $m)) {
                $sql .= ' AND b.booking_id = :ref';
                $params[':ref'] = (int) $m[1];
            } else {
                $sql .= ' AND c.name LIKE :q';
                $params[':q'] = '%' . addcslashes($search, '%_\\') . '%';
            }
        }
        $sql .= ' ORDER BY b.start_time, b.booking_id LIMIT 2001';
        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // 2. Takings: the value of COMPLETED appointments, day by day and service by service, at the price charged when booked.
    public static function takingsByDay(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT DATE(start_time) AS day, COUNT(*) AS appointments, COALESCE(SUM(price_charged), 0) AS value
             FROM Booking WHERE status = 'completed' AND start_time >= :from_time AND start_time < :to_time
             GROUP BY day ORDER BY day"
        );
        $stmt->execute(self::range($from, $to));
        return $stmt->fetchAll();
    }

    public static function takingsByService(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT s.name, COUNT(*) AS appointments, COALESCE(SUM(b.price_charged), 0) AS value
             FROM Booking b JOIN Service s ON s.service_id = b.service_id
             WHERE b.status = 'completed' AND b.start_time >= :from_time AND b.start_time < :to_time
             GROUP BY s.service_id, s.name ORDER BY value DESC, s.name"
        );
        $stmt->execute(self::range($from, $to));
        return $stmt->fetchAll();
    }

    // Loyalty rewards given in the period (clients exchange 100 points for R50 off in the salon, so the till takes less than
    // the value of the appointments). Returns ['points' => int, 'value' => rand].
    public static function rewardsGiven(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT COALESCE(SUM(-points), 0) FROM LoyaltyLedger
             WHERE reason = 'redeemed' AND created_at >= :from_time AND created_at < :to_time"
        );
        $stmt->execute(self::range($from, $to));
        $points = (int) $stmt->fetchColumn();
        return ['points' => $points, 'value' => intdiv($points, Loyalty::REDEEM_POINTS) * Loyalty::REDEEM_VALUE];
    }

    // 3. Stylist activity: for each stylist, the hours they were ROSTERED (their working blocks, counted for every day of the
    // period), the hours BOOKED (confirmed, completed and no-show appointments), what they completed, and what was lost to
    // cancellation. Utilisation is booked hours divided by rostered hours.
    public static function stylistActivity(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $days = array_fill(1, 7, 0);   // how many Mondays ... Sundays the period holds
        for ($d = $from->setTime(0, 0); $d <= $to->setTime(0, 0); $d = $d->modify('+1 day')) {
            $days[(int) $d->format('N')]++;
        }

        $stmt = Database::getConnection()->prepare(
            "SELECT st.stylist_id, st.name, st.accepting_bookings,
                    COALESCE(SUM(b.status = 'completed'), 0) AS completed,
                    COALESCE(SUM(b.status = 'no-show'), 0)   AS no_show,
                    COALESCE(SUM(b.status = 'cancelled'), 0) AS cancelled,
                    COALESCE(SUM(b.status = 'confirmed'), 0) AS upcoming,
                    COALESCE(SUM(CASE WHEN b.status IN ('confirmed', 'completed', 'no-show')
                                      THEN TIMESTAMPDIFF(MINUTE, b.start_time, b.end_time) END), 0) AS booked_minutes,
                    COALESCE(SUM(CASE WHEN b.status = 'cancelled'
                                      THEN TIMESTAMPDIFF(MINUTE, b.start_time, b.end_time) END), 0) AS cancelled_minutes,
                    COALESCE(SUM(CASE WHEN b.status = 'completed' THEN b.price_charged END), 0) AS value
             FROM Stylist st
             LEFT JOIN Booking b ON b.stylist_id = st.stylist_id AND b.start_time >= :from_time AND b.start_time < :to_time
             GROUP BY st.stylist_id, st.name, st.accepting_bookings
             ORDER BY st.name"
        );
        $stmt->execute(self::range($from, $to));
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $rostered = 0;
            foreach (Stylist::blocks((int) $r['stylist_id']) as $b) {
                [$sh, $sm] = array_map('intval', explode(':', $b['start_time']));
                [$eh, $em] = array_map('intval', explode(':', $b['end_time']));
                $rostered += $days[(int) $b['weekday']] * (($eh * 60 + $em) - ($sh * 60 + $sm));
            }
            $r['rostered_minutes'] = $rostered;
            $r['utilisation']      = $rostered > 0 ? ((int) $r['booked_minutes']) / $rostered : null;
        }
        unset($r);
        return $rows;
    }

    // 4. Cancellations: who cancelled what, when, and how much notice the salon was given (from the audit trail; bookings
    // cancelled before the audit trail existed show no notice). Counted by the day of the APPOINTMENT.
    public static function cancellations(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT b.booking_id, b.start_time, b.price_charged, c.name AS client_name, s.name AS service_name, st.name AS stylist_name,
                    (SELECT l.created_at FROM BookingLog l WHERE l.booking_id = b.booking_id AND l.action = 'cancelled'
                     ORDER BY l.created_at DESC, l.log_id DESC LIMIT 1) AS cancelled_at,
                    (SELECT l.actor FROM BookingLog l WHERE l.booking_id = b.booking_id AND l.action = 'cancelled'
                     ORDER BY l.created_at DESC, l.log_id DESC LIMIT 1) AS cancelled_by
             FROM Booking b
             JOIN Client  c  ON c.client_id   = b.client_id
             JOIN Service s  ON s.service_id  = b.service_id
             JOIN Stylist st ON st.stylist_id = b.stylist_id
             WHERE b.status = 'cancelled' AND b.start_time >= :from_time AND b.start_time < :to_time
             ORDER BY b.start_time, b.booking_id"
        );
        $stmt->execute(self::range($from, $to));
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['notice_minutes'] = ($r['cancelled_at'] === null) ? null
                : (int) round((strtotime($r['start_time']) - strtotime($r['cancelled_at'])) / 60);
        }
        unset($r);
        return $rows;
    }

    // No-shows: clients who did not arrive, in the period.
    public static function noShows(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT b.booking_id, b.start_time, b.price_charged, c.client_id, c.name AS client_name, s.name AS service_name, st.name AS stylist_name
             FROM Booking b
             JOIN Client  c  ON c.client_id   = b.client_id
             JOIN Service s  ON s.service_id  = b.service_id
             JOIN Stylist st ON st.stylist_id = b.stylist_id
             WHERE b.status = 'no-show' AND b.start_time >= :from_time AND b.start_time < :to_time
             ORDER BY b.start_time, b.booking_id"
        );
        $stmt->execute(self::range($from, $to));
        return $stmt->fetchAll();
    }

    // 5. Customer history: one client's complete record (every visit, what it cost, their loyalty balance and points).
    // Returns null if there is no such client. Allergy notes are never included.
    public static function customerHistory(int $clientId): ?array
    {
        $client = Loyalty::findClient($clientId);
        if ($client === null) {
            return null;
        }
        $visits = Loyalty::visits($clientId);       // newest first, at most 50
        $stmt = Database::getConnection()->prepare(
            "SELECT COUNT(*) AS bookings,
                    COALESCE(SUM(status = 'completed'), 0) AS completed,
                    COALESCE(SUM(status = 'no-show'), 0)   AS no_show,
                    COALESCE(SUM(status = 'cancelled'), 0) AS cancelled,
                    COALESCE(SUM(status = 'confirmed' AND start_time > :now), 0) AS upcoming,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN price_charged END), 0) AS spent,
                    MIN(CASE WHEN status = 'completed' THEN start_time END) AS first_visit,
                    MAX(CASE WHEN status = 'completed' THEN start_time END) AS last_visit
             FROM Booking WHERE client_id = :id"
        );
        $stmt->execute([':id' => $clientId, ':now' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')]);
        return [
            'client'  => $client,
            'totals'  => $stmt->fetch(),
            'visits'  => $visits,
            'balance' => Loyalty::balance($clientId),
            'ledger'  => Loyalty::ledger($clientId),
        ];
    }

    // The period as the two database values: from the start of the first day up to (not including) the day after the last.
    private static function range(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return [
            ':from_time' => $from->setTime(0, 0)->format('Y-m-d H:i:s'),
            ':to_time'   => $to->setTime(0, 0)->modify('+1 day')->format('Y-m-d H:i:s'),
        ];
    }
}
