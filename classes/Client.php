<?php
require_once __DIR__ . '/Database.php';

// Represents a person who books appointments.
class Client
{
    // Finds a client by email and updates their name and phone to the latest
    // details given, or creates a new client. Returns the client's id.
    public static function findOrCreate(string $name, string $email, ?string $phone): int
    {
        $db = Database::getConnection();

        $find = $db->prepare('SELECT client_id FROM Client WHERE email = :email ORDER BY client_id LIMIT 1');
        $find->execute([':email' => $email]);
        $existingId = $find->fetchColumn();

        if ($existingId !== false) {
            $update = $db->prepare('UPDATE Client SET name = :name, phone = :phone WHERE client_id = :id');
            $update->execute([':name' => $name, ':phone' => $phone, ':id' => $existingId]);
            return (int) $existingId;
        }

        $insert = $db->prepare('INSERT INTO Client (name, email, phone) VALUES (:name, :email, :phone)');
        $insert->execute([':name' => $name, ':email' => $email, ':phone' => $phone]);
        return (int) $db->lastInsertId();
    }
}