<?php
require_once __DIR__ . '/Database.php';

// The salon's stylists: which services each one performs, their working hours (as blocks), and whether they are
// currently taking bookings.
class Stylist
{
    // Position + 1 is the ISO weekday number: Mon = 1 ... Sun = 7.
    public const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    // A stylist's blocks, read once per request: stylist id => [weekday => [[start, end], ...]].
    private static array $blockCache = [];
    private static array $acceptingCache = [];

    public static function clearCache(): void
    {
        self::$blockCache     = [];
        self::$acceptingCache = [];
    }

    // Every stylist (login details are deliberately NOT selected).
    public static function getAll(): array
    {
        return Database::getConnection()->query(
            'SELECT stylist_id, name, accepting_bookings FROM Stylist ORDER BY name'
        )->fetchAll();
    }

    // The stylists a client can book for this service: taking bookings AND performing it.
    public static function getForService(int $serviceId): array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT s.stylist_id, s.name, s.accepting_bookings
             FROM Stylist s
             JOIN StylistService ss ON ss.stylist_id = s.stylist_id AND ss.service_id = :service_id
             WHERE s.accepting_bookings = 1
             ORDER BY s.name'
        );
        $stmt->execute([':service_id' => $serviceId]);
        return $stmt->fetchAll();
    }

    public static function performs(int $stylistId, int $serviceId): bool
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT COUNT(*) FROM StylistService WHERE stylist_id = :st AND service_id = :sv'
        );
        $stmt->execute([':st' => $stylistId, ':sv' => $serviceId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // The ids of the services a stylist performs.
    public static function serviceIds(int $stylistId): array
    {
        $stmt = Database::getConnection()->prepare('SELECT service_id FROM StylistService WHERE stylist_id = :id ORDER BY service_id');
        $stmt->execute([':id' => $stylistId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // For the home page: stylist id => the names of the services they perform.
    public static function serviceNamesByStylist(): array
    {
        $rows = Database::getConnection()->query(
            'SELECT ss.stylist_id, sv.name FROM StylistService ss JOIN Service sv ON sv.service_id = ss.service_id ORDER BY sv.name'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['stylist_id']][] = $r['name'];
        }
        return $out;
    }

    // For the administrator's list: includes the email and how many upcoming confirmed bookings each stylist has.
    public static function getAllForAdmin(): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT s.stylist_id, s.name, s.email, s.accepting_bookings,
                    (SELECT COUNT(*) FROM Booking b
                     WHERE b.stylist_id = s.stylist_id AND b.status = 'confirmed' AND b.start_time > :now) AS upcoming,
                    (SELECT COUNT(*) FROM Booking b2 WHERE b2.stylist_id = s.stylist_id) AS bookings
             FROM Stylist s
             ORDER BY s.name"
        );
        $stmt->execute([':now' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')]);
        return $stmt->fetchAll();
    }

    public static function findForAdmin(int $stylistId): ?array
    {
        $stmt = Database::getConnection()->prepare('SELECT stylist_id, name, email, accepting_bookings FROM Stylist WHERE stylist_id = :id');
        $stmt->execute([':id' => $stylistId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    // Is this stylist taking bookings right now?
    public static function isAccepting(int $stylistId): bool
    {
        if (!array_key_exists($stylistId, self::$acceptingCache)) {
            $stmt = Database::getConnection()->prepare('SELECT accepting_bookings FROM Stylist WHERE stylist_id = :id');
            $stmt->execute([':id' => $stylistId]);
            self::$acceptingCache[$stylistId] = ((int) $stmt->fetchColumn() === 1);
        }
        return self::$acceptingCache[$stylistId];
    }

    // Checks the add / edit form: name, email and password. Returns [clean values, errors].
    // $ignoreId is the stylist being edited (so their own email does not clash).
    // A password is required when adding; when editing, blank means "keep the current one".
    public static function validate(array $input, ?int $ignoreId, bool $passwordRequired): array
    {
        $errors   = [];
        $name     = trim((string) ($input['name'] ?? ''));
        $email    = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Please enter a name (up to 100 characters).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            $errors['email'] = 'Please enter a valid email address.';
        } elseif (self::emailInUse($email, $ignoreId)) {
            $errors['email'] = 'That email address is already used by another staff account.';
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

        return [['name' => $name, 'email' => $email, 'password' => ($password === '') ? null : $password], $errors];
    }

    // Checks the ticked services: at least one, and every one must exist. Returns [clean ids, error or null].
    public static function validateServiceIds($raw): array
    {
        $ids = [];
        foreach ((is_array($raw) ? $raw : []) as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id !== false && $id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if (count($ids) === 0) {
            return [[], 'Please tick at least one service this stylist performs.'];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = Database::getConnection()->prepare("SELECT COUNT(*) FROM Service WHERE service_id IN ($marks)");
        $stmt->execute($ids);
        if ((int) $stmt->fetchColumn() !== count($ids)) {
            return [[], 'One of the ticked services does not exist.'];
        }
        return [$ids, null];
    }

    // The starting hours from the add form (first day to last day, opening to closing) as blocks: [[weekday, open, close], ...].
    // A range such as Sat to Mon wraps past Sunday. Returns null if any part is not valid.
    public static function initialBlocks(string $firstDay, string $lastDay, string $open, string $close): ?array
    {
        $first = array_search($firstDay, self::DAYS, true);
        $last  = array_search($lastDay, self::DAYS, true);
        if ($first === false || $last === false || !self::validTime($open) || !self::validTime($close) || $open >= $close) {
            return null;
        }
        $blocks = [];
        for ($d = $first; ; $d = ($d + 1) % 7) {
            $blocks[] = [$d + 1, $open, $close];
            if ($d === $last) {
                break;
            }
        }
        return $blocks;
    }

    // Adds a stylist with the services they perform and their starting blocks, all together or not at all. Returns the new id.
    public static function create(array $clean, array $serviceIds, array $blocks): int
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO Stylist (name, email, password_hash) VALUES (:name, :email, :hash)')
               ->execute([':name' => $clean['name'], ':email' => $clean['email'], ':hash' => password_hash($clean['password'], PASSWORD_DEFAULT)]);
            $id = (int) $db->lastInsertId();
            $ss = $db->prepare('INSERT INTO StylistService (stylist_id, service_id) VALUES (:st, :sv)');
            foreach ($serviceIds as $serviceId) {
                $ss->execute([':st' => $id, ':sv' => $serviceId]);
            }
            $wb = $db->prepare('INSERT INTO WorkingBlock (stylist_id, weekday, start_time, end_time) VALUES (:st, :day, :open, :close)');
            foreach ($blocks as [$day, $open, $close]) {
                $wb->execute([':st' => $id, ':day' => $day, ':open' => $open . ':00', ':close' => $close . ':00']);
            }
            $db->commit();
            self::clearCache();
            return $id;
        } catch (Throwable $ex) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $ex;
        }
    }

    // Changes a stylist's name and email. The password only changes if a new one was given.
    public static function update(int $stylistId, array $clean): void
    {
        $sql    = 'UPDATE Stylist SET name = :name, email = :email';
        $params = [':name' => $clean['name'], ':email' => $clean['email'], ':id' => $stylistId];
        if ($clean['password'] !== null) {
            $sql .= ', password_hash = :hash';
            $params[':hash'] = password_hash($clean['password'], PASSWORD_DEFAULT);
        }
        Database::getConnection()->prepare($sql . ' WHERE stylist_id = :id')->execute($params);
    }

    // Saves the ticked services. Locks the stylist first, so a booking being made at that moment sees either the old
    // list or the new one, never a mixture. Returns '' when it worked, otherwise a message.
    public static function setServices(int $stylistId, $rawServiceIds): string
    {
        [$ids, $error] = self::validateServiceIds($rawServiceIds);
        if ($error !== null) {
            return $error;
        }
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT stylist_id FROM Stylist WHERE stylist_id = :id FOR UPDATE');
            $lock->execute([':id' => $stylistId]);
            if ($lock->fetchColumn() === false) {
                $db->rollBack();
                return 'We could not find that stylist.';
            }
            $db->prepare('DELETE FROM StylistService WHERE stylist_id = :id')->execute([':id' => $stylistId]);
            $ins = $db->prepare('INSERT INTO StylistService (stylist_id, service_id) VALUES (:st, :sv)');
            foreach ($ids as $serviceId) {
                $ins->execute([':st' => $stylistId, ':sv' => $serviceId]);
            }
            $db->commit();
            return '';
        } catch (Throwable $ex) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $ex;
        }
    }

    // Switches "taking bookings" on or off. Bookings already made stay as they are.
    public static function setAccepting(int $stylistId, bool $on): bool
    {
        $stmt = Database::getConnection()->prepare('UPDATE Stylist SET accepting_bookings = :on WHERE stylist_id = :id');
        $stmt->execute([':on' => $on ? 1 : 0, ':id' => $stylistId]);
        self::clearCache();
        return self::findForAdmin($stylistId) !== null;
    }

    // Deletes a stylist who has never had a booking (their services and blocks go with them). A stylist who appears in
    // any booking cannot be deleted, because the booking history would lose its meaning: use "Stop taking bookings".
    public static function delete(int $stylistId): string
    {
        $db = Database::getConnection();
        if (self::findForAdmin($stylistId) === null) {
            return 'We could not find that stylist.';
        }
        $used = $db->prepare('SELECT COUNT(*) FROM Booking WHERE stylist_id = :id');
        $used->execute([':id' => $stylistId]);
        if ((int) $used->fetchColumn() > 0) {
            return 'That stylist appears in existing bookings, so they cannot be deleted. Use "Stop taking bookings" instead.';
        }
        $db->prepare('DELETE FROM Stylist WHERE stylist_id = :id')->execute([':id' => $stylistId]);
        self::clearCache();
        return '';
    }

    // ---------------------------------------------------------------- working hours as blocks

    // All of a stylist's blocks, for the administrator: block_id, weekday, start, end (HH:MM), in order.
    public static function blocks(int $stylistId): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT block_id, weekday, TIME_FORMAT(start_time, '%H:%i') AS start_time, TIME_FORMAT(end_time, '%H:%i') AS end_time
             FROM WorkingBlock WHERE stylist_id = :id ORDER BY weekday, start_time"
        );
        $stmt->execute([':id' => $stylistId]);
        return $stmt->fetchAll();
    }

    // The blocks a stylist works on one date, as [[opening, closing], ...] (DateTimeImmutable), earliest first.
    // An empty list means they do not work that day. An appointment must fit entirely inside ONE block.
    public static function blocksOn(int $stylistId, DateTimeImmutable $date): array
    {
        if (!isset(self::$blockCache[$stylistId])) {
            $map  = [];
            $stmt = Database::getConnection()->prepare(
                "SELECT weekday, TIME_FORMAT(start_time, '%H:%i') AS s, TIME_FORMAT(end_time, '%H:%i') AS e
                 FROM WorkingBlock WHERE stylist_id = :id ORDER BY weekday, start_time"
            );
            $stmt->execute([':id' => $stylistId]);
            foreach ($stmt->fetchAll() as $row) {
                $map[(int) $row['weekday']][] = [$row['s'], $row['e']];
            }
            self::$blockCache[$stylistId] = $map;
        }
        $out = [];
        foreach (self::$blockCache[$stylistId][(int) $date->format('N')] ?? [] as [$s, $e]) {
            [$sh, $sm] = array_map('intval', explode(':', $s));
            [$eh, $em] = array_map('intval', explode(':', $e));
            $out[] = [$date->setTime($sh, $sm), $date->setTime($eh, $em)];
        }
        return $out;
    }

    // Adds a block. It may not overlap another block on the same day (touching is fine: 09:00-13:00 then 13:00-17:00).
    // The stylist is locked while it is checked and saved. Returns '' when it worked, otherwise a message.
    public static function addBlock(int $stylistId, int $weekday, string $from, string $to): string
    {
        if ($weekday < 1 || $weekday > 7) {
            return 'Please choose a day.';
        }
        if (!self::validTime($from) || !self::validTime($to)) {
            return 'Please enter times like 09:00.';
        }
        if ($from >= $to) {
            return 'The start time must be earlier than the end time.';
        }
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT stylist_id FROM Stylist WHERE stylist_id = :id FOR UPDATE');
            $lock->execute([':id' => $stylistId]);
            if ($lock->fetchColumn() === false) {
                $db->rollBack();
                return 'We could not find that stylist.';
            }
            $clash = $db->prepare(
                'SELECT COUNT(*) FROM WorkingBlock WHERE stylist_id = :id AND weekday = :day AND start_time < :to AND end_time > :from'
            );
            $clash->execute([':id' => $stylistId, ':day' => $weekday, ':from' => $from . ':00', ':to' => $to . ':00']);
            if ((int) $clash->fetchColumn() > 0) {
                $db->rollBack();
                return 'That overlaps another block on the same day. Change or remove that block first.';
            }
            $db->prepare('INSERT INTO WorkingBlock (stylist_id, weekday, start_time, end_time) VALUES (:id, :day, :from, :to)')
               ->execute([':id' => $stylistId, ':day' => $weekday, ':from' => $from . ':00', ':to' => $to . ':00']);
            $db->commit();
            self::clearCache();
            return '';
        } catch (Throwable $ex) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $ex;
        }
    }

    public static function removeBlock(int $blockId, int $stylistId): string
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT stylist_id FROM Stylist WHERE stylist_id = :id FOR UPDATE');
            $lock->execute([':id' => $stylistId]);
            $del = $db->prepare('DELETE FROM WorkingBlock WHERE block_id = :block AND stylist_id = :id');
            $del->execute([':block' => $blockId, ':id' => $stylistId]);
            $removed = $del->rowCount() === 1;
            $db->commit();
            self::clearCache();
            return $removed ? '' : 'We could not find that block.';
        } catch (Throwable $ex) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $ex;
        }
    }

    // Working hours in plain words for lists, for example "Mon to Fri 09:00 to 13:00, 14:00 to 17:00; Sat 08:00 to 14:00".
    public static function describeBlocks(array $blocks): string
    {
        if (count($blocks) === 0) {
            return 'No working hours set';
        }
        $byDay = [];
        foreach ($blocks as $b) {
            $byDay[(int) $b['weekday']][] = $b['start_time'] . ' to ' . $b['end_time'];
        }
        $groups = [];   // [first day, last day, hours text]
        for ($d = 1; $d <= 7; $d++) {
            if (!isset($byDay[$d])) {
                continue;
            }
            $text = implode(', ', $byDay[$d]);
            $n    = count($groups);
            if ($n > 0 && $groups[$n - 1][1] === $d - 1 && $groups[$n - 1][2] === $text) {
                $groups[$n - 1][1] = $d;
            } else {
                $groups[] = [$d, $d, $text];
            }
        }
        $parts = [];
        foreach ($groups as [$first, $last, $text]) {
            $days    = ($first === $last) ? self::DAYS[$first - 1] : self::DAYS[$first - 1] . ' to ' . self::DAYS[$last - 1];
            $parts[] = $days . ' ' . $text;
        }
        return implode('; ', $parts);
    }

    // How many UPCOMING confirmed bookings no longer fit this stylist: outside every block, or for a service they no longer
    // perform. Used to warn the administrator after hours or services are changed (the bookings are not touched).
    public static function strandedBookings(int $stylistId): int
    {
        self::clearCache();
        $stmt = Database::getConnection()->prepare(
            "SELECT start_time, end_time, service_id FROM Booking
             WHERE stylist_id = :id AND status = 'confirmed' AND start_time > :now"
        );
        $stmt->execute([':id' => $stylistId, ':now' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')]);
        $performed = self::serviceIds($stylistId);

        $count = 0;
        foreach ($stmt->fetchAll() as $row) {
            $start = new DateTimeImmutable($row['start_time']);
            $end   = new DateTimeImmutable($row['end_time']);
            $fits  = false;
            foreach (self::blocksOn($stylistId, $start->setTime(0, 0)) as [$open, $close]) {
                if ($start >= $open && $end <= $close) {
                    $fits = true;
                    break;
                }
            }
            if (!$fits || !in_array((int) $row['service_id'], $performed, true)) {
                $count++;
            }
        }
        return $count;
    }

    private static function validTime(string $text): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $text);
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
