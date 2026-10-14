<?php
require_once __DIR__ . '/Database.php';

// Represents a person who books appointments.
class Client
{
    // Finds a client by email and updates their name and phone to the latest
    // details given, or creates a new client. Returns the client's id.
    // A client with no email address (for example someone who phoned the salon) is recognised
    // again only when BOTH the name and the phone number (digits only) are the same, so two
    // different people who share a phone are never merged. This is what lets a phone-only
    // client keep earning loyalty points.
    public static function findOrCreate(string $name, ?string $email, ?string $phone): int
    {
        $db = Database::getConnection();

        if ($email === null) {
            $digits = preg_replace('/\D+/', '', (string) $phone);
            if ($digits !== '') {
                $same = $db->prepare(
                    "SELECT client_id FROM Client
                     WHERE email IS NULL AND LOWER(name) = LOWER(:name)
                       AND REGEXP_REPLACE(phone, '[^0-9]', '') = :digits
                     ORDER BY client_id LIMIT 1"
                );
                $same->execute([':name' => trim($name), ':digits' => $digits]);
                $knownId = $same->fetchColumn();
                if ($knownId !== false) {
                    return (int) $knownId;
                }
            }
            $db->prepare('INSERT INTO Client (name, email, phone) VALUES (:name, NULL, :phone)')
               ->execute([':name' => $name, ':phone' => $phone]);
            return (int) $db->lastInsertId();
        }

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
