<?php
require_once __DIR__ . '/Database.php';

// Represents the salon's stylists and their working hours.
class Stylist
{
    // Returns every stylist (login details are deliberately NOT selected).
    public static function getAll(): array
    {
        $db   = Database::getConnection();
        $stmt = $db->query(
            'SELECT stylist_id, name, specialty, working_hours
             FROM Stylist
             ORDER BY name'
        );
        return $stmt->fetchAll();
    }

    // Works out when a stylist is at work on a given day.
    // $workingHours looks like 'Mon-Fri 09:00-17:00'.
    // Returns [opening time, closing time], or null if they do not work that day.
    public static function getWorkingWindow(string $workingHours, DateTimeImmutable $date): ?array
    {
        $dayNumbers = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6, 'Sun' => 7];
        $pattern    = '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)-(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+(\d{2}):(\d{2})-(\d{2}):(\d{2})$/';

        if (!preg_match($pattern, trim($workingHours), $m)) {
            return null; // unreadable working hours: treat as not working
        }

        $firstDay = $dayNumbers[$m[1]];
        $lastDay  = $dayNumbers[$m[2]];
        $today    = (int) $date->format('N'); // 1 = Monday ... 7 = Sunday

        if ($firstDay <= $lastDay) {
            $works = ($today >= $firstDay && $today <= $lastDay);
        } else { // a range that wraps past Sunday, e.g. Sat-Mon
            $works = ($today >= $firstDay || $today <= $lastDay);
        }
        if (!$works) {
            return null;
        }

        $open  = $date->setTime((int) $m[3], (int) $m[4]);
        $close = $date->setTime((int) $m[5], (int) $m[6]);

        return ($close > $open) ? [$open, $close] : null;
    }
}