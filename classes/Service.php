<?php
require_once __DIR__ . '/Database.php';

// Represents the services the salon offers (cut, colour, treatment...).
class Service
{
    // Returns every service as an array, sorted by name.
    public static function getAll(): array
    {
        $db   = Database::getConnection();
        $stmt = $db->query(
            'SELECT service_id, name, description, duration_minutes, price
             FROM Service
             ORDER BY name'
        );
        return $stmt->fetchAll();
    }

    // Returns one service by its id, or null if it does not exist.
    public static function find(int $serviceId): ?array
    {
        $db   = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT service_id, name, description, duration_minutes, price
             FROM Service
             WHERE service_id = :id'
        );
        $stmt->execute([':id' => $serviceId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}