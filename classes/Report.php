<?php
require_once __DIR__ . '/Database.php';

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

    // The period as the two database values: from the start of the first day up to (not including) the day after the last.
    private static function range(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return [
            ':from_time' => $from->setTime(0, 0)->format('Y-m-d H:i:s'),
            ':to_time'   => $to->setTime(0, 0)->modify('+1 day')->format('Y-m-d H:i:s'),
        ];
    }
}
