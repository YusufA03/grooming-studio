<?php
require_once __DIR__ . '/Database.php';

// The audit trail: a permanent record of who did what to a booking, and when.
// (Phase 3, Section 3.4 names "no audit trail" as a weakness of the paper diary.)
class AuditLog
{
    public const ACTIONS = ['created', 'rescheduled', 'cancelled', 'completed', 'no-show', 'reminder-sent'];

    // Adds one line to a booking's history. The database connection is passed in so that the
    // line is saved in the SAME transaction as the change it describes: both happen, or neither.
    public static function record(PDO $db, int $bookingId, string $action, string $actor, ?string $detail = null): void
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Unknown audit action: ' . $action);
        }
        $db->prepare(
            'INSERT INTO BookingLog (booking_id, action, actor, detail, created_at)
             VALUES (:booking_id, :action, :actor, :detail, :created_at)'
        )->execute([
            ':booking_id' => $bookingId,
            ':action'     => $action,
            ':actor'      => mb_substr($actor, 0, 120),
            ':detail'     => ($detail === null) ? null : mb_substr($detail, 0, 255),
            ':created_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ]);
    }

    // A booking's history, oldest line first.
    public static function forBooking(int $bookingId): array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT log_id, action, actor, detail, created_at
             FROM BookingLog WHERE booking_id = :id ORDER BY created_at, log_id'
        );
        $stmt->execute([':id' => $bookingId]);
        return $stmt->fetchAll();
    }
}
