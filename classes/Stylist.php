<?php
require_once __DIR__ . '/Database.php';

// Represents the salon's stylists and their working hours.
class Stylist
{
    public const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    // Stored in place of working hours when a stylist is not taking bookings.
    public const OFF = 'Off';

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

    // For the administrator's list: includes the email and how many upcoming
    // confirmed bookings each stylist has. Never includes the password hash.
    public static function getAllForAdmin(): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT s.stylist_id, s.name, s.email, s.specialty, s.working_hours,
                    (SELECT COUNT(*) FROM Booking b
                     WHERE b.stylist_id = s.stylist_id
                       AND b.status = 'confirmed'
                       AND b.start_time > :now) AS upcoming,
                    (SELECT COUNT(*) FROM Booking b2 WHERE b2.stylist_id = s.stylist_id) AS bookings
             FROM Stylist s
             ORDER BY s.name"
        );
        $stmt->execute([':now' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')]);
        return $stmt->fetchAll();
    }

    // One stylist for the edit form (no password hash), or null.
    public static function findForAdmin(int $stylistId): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT stylist_id, name, email, specialty, working_hours
             FROM Stylist
             WHERE stylist_id = :id'
        );
        $stmt->execute([':id' => $stylistId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    // Checks what the administrator typed into the add / edit form.
    // Returns [clean values, errors]. Nothing should be saved if there are errors.
    // $ignoreId is the stylist being edited (so their own email does not clash).
    // A password is required when adding; when editing, blank means "keep the current one".
    public static function validate(array $input, ?int $ignoreId, bool $passwordRequired): array
    {
        $errors    = [];
        $name      = trim((string) ($input['name'] ?? ''));
        $email     = trim((string) ($input['email'] ?? ''));
        $specialty = trim((string) ($input['specialty'] ?? ''));
        $password  = (string) ($input['password'] ?? '');

        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Please enter a name (up to 100 characters).';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            $errors['email'] = 'Please enter a valid email address.';
        } elseif (self::emailInUse($email, $ignoreId)) {
            $errors['email'] = 'That email address is already used by another staff account.';
        }

        if (mb_strlen($specialty) > 100) {
            $errors['specialty'] = 'The specialty can be up to 100 characters.';
        }

        if (!empty($input['hours_off'])) {
            $hours = self::OFF;
        } else {
            $hours = self::buildWorkingHours(
                (string) ($input['first_day'] ?? ''),
                (string) ($input['last_day'] ?? ''),
                (string) ($input['open_time'] ?? ''),
                (string) ($input['close_time'] ?? '')
            );
            if ($hours === null) {
                $errors['working_hours'] = 'Please choose valid working days, and an opening time earlier than the closing time.';
            }
        }

        if ($password === '' && $passwordRequired) {
            $errors['password'] = 'Please set a password for this stylist.';
        } elseif ($password !== '') {
            if (strlen($password) < 10) {
                $errors['password'] = 'The password must be at least 10 characters long.';
            } elseif (strlen($password) > 72) {
                $errors['password'] = 'The password can be at most 72 characters long.';
            }
        }

        $clean = [
            'name'          => $name,
            'email'         => $email,
            'specialty'     => ($specialty === '') ? null : $specialty,
            'working_hours' => $hours,
            'password'      => ($password === '') ? null : $password,
        ];
        return [$clean, $errors];
    }

    // Adds a stylist. Returns the new id. Use validate() first.
    public static function create(array $clean): int
    {
        $db = Database::getConnection();
        $db->prepare(
            'INSERT INTO Stylist (name, email, password_hash, specialty, working_hours)
             VALUES (:name, :email, :hash, :specialty, :hours)'
        )->execute([
            ':name'      => $clean['name'],
            ':email'     => $clean['email'],
            ':hash'      => password_hash($clean['password'], PASSWORD_DEFAULT),
            ':specialty' => $clean['specialty'],
            ':hours'     => $clean['working_hours'],
        ]);
        return (int) $db->lastInsertId();
    }

    // Changes a stylist. The password only changes if a new one was given.
    // Use validate() first.
    public static function update(int $stylistId, array $clean): void
    {
        $sql    = 'UPDATE Stylist SET name = :name, email = :email, specialty = :specialty, working_hours = :hours';
        $params = [
            ':name'      => $clean['name'],
            ':email'     => $clean['email'],
            ':specialty' => $clean['specialty'],
            ':hours'     => $clean['working_hours'],
            ':id'        => $stylistId,
        ];
        if ($clean['password'] !== null) {
            $sql .= ', password_hash = :hash';
            $params[':hash'] = password_hash($clean['password'], PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE stylist_id = :id';

        Database::getConnection()->prepare($sql)->execute($params);
    }

    // Deletes a stylist who has never had a booking. A stylist who appears in
    // any booking (even an old one) cannot be deleted, because the booking
    // history would lose its meaning. Use "Not taking bookings" for them instead.
    // Returns '' when it worked, otherwise a message explaining why not.
    public static function delete(int $stylistId): string
    {
        $db = Database::getConnection();

        if (self::findForAdmin($stylistId) === null) {
            return 'We could not find that stylist.';
        }

        $used = $db->prepare('SELECT COUNT(*) FROM Booking WHERE stylist_id = :id');
        $used->execute([':id' => $stylistId]);
        if ((int) $used->fetchColumn() > 0) {
            return 'That stylist appears in existing bookings, so they cannot be deleted. '
                 . 'Edit them and tick "Not taking bookings" instead.';
        }

        $db->prepare('DELETE FROM Stylist WHERE stylist_id = :id')->execute([':id' => $stylistId]);
        return '';
    }

    // How many UPCOMING confirmed bookings would fall outside these working
    // hours (a day they no longer work, or before opening / after closing).
    // Used to warn the administrator after hours are changed.
    public static function countUpcomingOutsideHours(int $stylistId, string $workingHours): int
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT start_time, end_time
             FROM Booking
             WHERE stylist_id = :id AND status = 'confirmed' AND start_time > :now"
        );
        $stmt->execute([
            ':id'  => $stylistId,
            ':now' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ]);

        $outside = 0;
        foreach ($stmt->fetchAll() as $row) {
            $start  = new DateTimeImmutable($row['start_time']);
            $end    = new DateTimeImmutable($row['end_time']);
            $window = self::getWorkingWindow($workingHours, $start->setTime(0, 0));

            if ($window === null || $start < $window[0] || $end > $window[1]) {
                $outside++;
            }
        }
        return $outside;
    }

    // Builds the stored working-hours text, for example 'Mon-Fri 09:00-17:00'.
    // Returns null if any part is not valid.
    public static function buildWorkingHours(string $firstDay, string $lastDay, string $open, string $close): ?string
    {
        $timePattern = '/^([01]\d|2[0-3]):[0-5]\d$/';

        if (!in_array($firstDay, self::DAYS, true) || !in_array($lastDay, self::DAYS, true)) {
            return null;
        }
        if (!preg_match($timePattern, $open) || !preg_match($timePattern, $close) || $open >= $close) {
            return null;
        }
        return $firstDay . '-' . $lastDay . ' ' . $open . '-' . $close;
    }

    // Reads stored working hours back into parts for the edit form.
    // Returns ['off' => true] for "Off", ['off' => false, first, last, open, close],
    // or null if the text cannot be understood.
    public static function parseWorkingHours(string $text): ?array
    {
        $text = trim($text);
        if ($text === self::OFF) {
            return ['off' => true];
        }
        if (!preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)-(Mon|Tue|Wed|Thu|Fri|Sat|Sun) (\d{2}:\d{2})-(\d{2}:\d{2})$/', $text, $m)) {
            return null;
        }
        return ['off' => false, 'first' => $m[1], 'last' => $m[2], 'open' => $m[3], 'close' => $m[4]];
    }

    // Working hours in plain words, for lists.
    public static function describeWorkingHours(string $text): string
    {
        $parts = self::parseWorkingHours($text);
        if ($parts === null) {
            return 'Not set correctly';
        }
        if ($parts['off']) {
            return 'Not taking bookings';
        }
        $days = ($parts['first'] === $parts['last']) ? $parts['first'] : $parts['first'] . ' to ' . $parts['last'];
        return $days . ', ' . $parts['open'] . ' to ' . $parts['close'];
    }

    // Works out when a stylist is at work on a given day.
    // $workingHours looks like 'Mon-Fri 09:00-17:00'.
    // Returns [opening time, closing time], or null if they do not work that day.
    public static function getWorkingWindow(string $workingHours, DateTimeImmutable $date): ?array
    {
        $dayNumbers = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6, 'Sun' => 7];
        $pattern    = '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)-(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+(\d{2}):(\d{2})-(\d{2}):(\d{2})$/';

        if (!preg_match($pattern, trim($workingHours), $m)) {
            return null; // unreadable working hours (or "Off"): treat as not working
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

    // Is this email already used by another stylist or by an administrator?
    private static function emailInUse(string $email, ?int $ignoreStylistId): bool
    {
        $db = Database::getConnection();

        if ($ignoreStylistId === null) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM Stylist WHERE email = :email');
            $stmt->execute([':email' => $email]);
        } else {
            $stmt = $db->prepare('SELECT COUNT(*) FROM Stylist WHERE email = :email AND stylist_id <> :id');
            $stmt->execute([':email' => $email, ':id' => $ignoreStylistId]);
        }
        if ((int) $stmt->fetchColumn() > 0) {
            return true;
        }

        $admin = $db->prepare('SELECT COUNT(*) FROM Administrator WHERE email = :email');
        $admin->execute([':email' => $email]);
        return (int) $admin->fetchColumn() > 0;
    }
}
