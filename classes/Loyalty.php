<?php
require_once __DIR__ . '/Database.php';

// Loyalty points (Phase 1, Section 7). A client earns points for every COMPLETED appointment and
// can exchange points in the salon for a reward. Every earning, redemption and correction is a
// row in LoyaltyLedger, so a balance can always be explained line by line.
class Loyalty
{
    public const RAND_PER_POINT = 10;    // 1 point for every R10 of a completed appointment
    public const REDEEM_POINTS  = 100;   // 100 points ...
    public const REDEEM_VALUE   = 50;    // ... can be exchanged in the salon for R50 off, or a free add-on worth about that

    // How many points an appointment worth $price earns.
    public static function pointsFor($price): int
    {
        return (int) floor(((float) $price) / self::RAND_PER_POINT);
    }

    // Gives the client the points for a completed booking. Called inside the same transaction that
    // marks the booking completed. Safe to call twice: the unique booking_id means one booking can
    // earn points only once. Returns the points given (0 if none, or if already given).
    public static function awardForBooking(PDO $db, int $bookingId): int
    {
        $stmt = $db->prepare(
            "SELECT b.client_id, b.price_charged, s.name AS service_name
             FROM Booking b JOIN Service s ON s.service_id = b.service_id
             WHERE b.booking_id = :id AND b.status = 'completed'"
        );
        $stmt->execute([':id' => $bookingId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return 0;
        }
        $points = self::pointsFor($row['price_charged']);
        if ($points <= 0) {
            return 0;
        }
        $insert = $db->prepare(
            "INSERT IGNORE INTO LoyaltyLedger (client_id, booking_id, points, reason, note, actor, created_at)
             VALUES (:client_id, :booking_id, :points, 'earned', :note, 'System', :created_at)"
        );
        $insert->execute([
            ':client_id'  => $row['client_id'],
            ':booking_id' => $bookingId,
            ':points'     => $points,
            ':note'       => mb_substr('Completed: ' . $row['service_name'], 0, 255),
            ':created_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ]);
        return ($insert->rowCount() === 1) ? $points : 0;
    }

    // The points one booking earned (0 if none).
    public static function earnedForBooking(int $bookingId): int
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT COALESCE(SUM(points), 0) FROM LoyaltyLedger WHERE booking_id = :id AND reason = 'earned'"
        );
        $stmt->execute([':id' => $bookingId]);
        return (int) $stmt->fetchColumn();
    }

    public static function balance(int $clientId): int
    {
        $stmt = Database::getConnection()->prepare('SELECT COALESCE(SUM(points), 0) FROM LoyaltyLedger WHERE client_id = :id');
        $stmt->execute([':id' => $clientId]);
        return (int) $stmt->fetchColumn();
    }

    // Every line of a client's points, newest first.
    public static function ledger(int $clientId): array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT points, reason, note, actor, created_at FROM LoyaltyLedger
             WHERE client_id = :id ORDER BY created_at DESC, ledger_id DESC LIMIT 200'
        );
        $stmt->execute([':id' => $clientId]);
        return $stmt->fetchAll();
    }

    // Clients with their balances, for the administrator's loyalty page. $search matches name, email or phone.
    public static function searchClients(string $search, int $limit = 100): array
    {
        $sql = "SELECT c.client_id, c.name, c.email, c.phone,
                       COALESCE(SUM(l.points), 0) AS balance,
                       (SELECT COUNT(*) FROM Booking b WHERE b.client_id = c.client_id AND b.status = 'completed') AS visits
                FROM Client c
                LEFT JOIN LoyaltyLedger l ON l.client_id = c.client_id";
        $params = [];
        if ($search !== '') {
            $like       = '%' . addcslashes($search, '%_\\') . '%';
            $conditions = 'c.name LIKE :q1 OR c.email LIKE :q2 OR c.phone LIKE :q3';
            $params     = [':q1' => $like, ':q2' => $like, ':q3' => $like];
            // A phone number can be saved as "082 123 4567" or "0821234567": compare the digits only.
            $digits = preg_replace('/\D+/', '', $search);
            if (strlen($digits) >= 3) {
                $conditions .= " OR REGEXP_REPLACE(c.phone, '[^0-9]', '') LIKE :q4";
                $params[':q4'] = '%' . $digits . '%';
            }
            $sql .= ' WHERE ' . $conditions;
        }
        $sql .= ' GROUP BY c.client_id, c.name, c.email, c.phone ORDER BY balance DESC, c.name LIMIT ' . (int) $limit;
        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function findClient(int $clientId): ?array
    {
        $stmt = Database::getConnection()->prepare('SELECT client_id, name, email, phone FROM Client WHERE client_id = :id');
        $stmt->execute([':id' => $clientId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    // A client's appointments, newest first (their visit history).
    public static function visits(int $clientId): array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT b.booking_id, b.start_time, b.status, b.price_charged, s.name AS service_name, st.name AS stylist_name
             FROM Booking b
             JOIN Service s  ON s.service_id  = b.service_id
             JOIN Stylist st ON st.stylist_id = b.stylist_id
             WHERE b.client_id = :id ORDER BY b.start_time DESC LIMIT 50'
        );
        $stmt->execute([':id' => $clientId]);
        return $stmt->fetchAll();
    }

    // Exchanges whole blocks of 100 points for a reward in the salon. Returns '' when it worked,
    // otherwise a message. The client's row is locked while the balance is checked, so two
    // administrators cannot spend the same points twice.
    public static function redeem(int $clientId, int $blocks, string $note, string $actor): string
    {
        if ($blocks < 1 || $blocks > 20) {
            return 'Choose between 1 and 20 rewards.';
        }
        $note = trim($note);
        if (mb_strlen($note) > 200) {
            return 'The note can be up to 200 characters.';
        }
        if ($note === '') {
            $note = 'R' . ($blocks * self::REDEEM_VALUE) . ' reward';
        }
        $points = $blocks * self::REDEEM_POINTS;

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT client_id FROM Client WHERE client_id = :id FOR UPDATE');
            $lock->execute([':id' => $clientId]);
            if ($lock->fetchColumn() === false) {
                $db->rollBack();
                return 'We could not find that client.';
            }
            $sum = $db->prepare('SELECT COALESCE(SUM(points), 0) FROM LoyaltyLedger WHERE client_id = :id');
            $sum->execute([':id' => $clientId]);
            $balance = (int) $sum->fetchColumn();
            if ($balance < $points) {
                $db->rollBack();
                return 'Not enough points: this client has ' . $balance . ' and ' . $points . ' are needed.';
            }
            $db->prepare(
                "INSERT INTO LoyaltyLedger (client_id, booking_id, points, reason, note, actor, created_at)
                 VALUES (:client_id, NULL, :points, 'redeemed', :note, :actor, :created_at)"
            )->execute([
                ':client_id'  => $clientId,
                ':points'     => -$points,
                ':note'       => $note,
                ':actor'      => mb_substr($actor, 0, 120),
                ':created_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            ]);
            $db->commit();
            return '';
        } catch (Throwable $ex) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $ex;
        }
    }

    // A manual correction by the administrator (for example a goodwill bonus). A reason is required
    // and the balance may never go below zero. Returns '' when it worked, otherwise a message.
    public static function adjust(int $clientId, int $points, string $note, string $actor): string
    {
        if ($points === 0 || $points < -1000 || $points > 1000) {
            return 'Enter a number of points between -1000 and 1000 (not zero).';
        }
        $note = trim($note);
        if (mb_strlen($note) < 3 || mb_strlen($note) > 200) {
            return 'Please give a reason (3 to 200 characters).';
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT client_id FROM Client WHERE client_id = :id FOR UPDATE');
            $lock->execute([':id' => $clientId]);
            if ($lock->fetchColumn() === false) {
                $db->rollBack();
                return 'We could not find that client.';
            }
            $sum = $db->prepare('SELECT COALESCE(SUM(points), 0) FROM LoyaltyLedger WHERE client_id = :id');
            $sum->execute([':id' => $clientId]);
            if ((int) $sum->fetchColumn() + $points < 0) {
                $db->rollBack();
                return 'That would take the balance below zero.';
            }
            $db->prepare(
                "INSERT INTO LoyaltyLedger (client_id, booking_id, points, reason, note, actor, created_at)
                 VALUES (:client_id, NULL, :points, 'adjustment', :note, :actor, :created_at)"
            )->execute([
                ':client_id'  => $clientId,
                ':points'     => $points,
                ':note'       => $note,
                ':actor'      => mb_substr($actor, 0, 120),
                ':created_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            ]);
            $db->commit();
            return '';
        } catch (Throwable $ex) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $ex;
        }
    }
}
